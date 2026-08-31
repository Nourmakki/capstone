from fastapi import (
    FastAPI,
    File,
    Form,
    HTTPException,
    UploadFile,
)
import shutil
from pathlib import Path
from tempfile import NamedTemporaryFile
from pydantic import ValidationError

from api.schema import (AdminDecisionRequest,StartWorkflowRequest,WorkflowResponse,)
from api.response_mapper import build_workflow_response
from graph.workflow_options import (start_roadmap_workflow,resume_roadmap_workflow,get_roadmap_workflow_state,)
from graph.state import AgentState

from api.learning.schema import (
    LearningManagerDecisionRequest,
    LearningWorkflowResponse,
    StartLearningWorkflowRequest,
)
from api.learning.response_mapper import (
    build_learning_workflow_response,
)
from learning_workflow.graph.workflow_options import (
    start_learning_workflow,
    resume_learning_workflow,
    get_learning_workflow_state,
)
from learning_workflow.graph.state import (
    LearningAgentState,
)


from api.project_assessment.schema import (
    ProjectAssessmentWorkflowResponse,
    StartProjectAssessmentRequest,
)

from api.project_assessment.response_mapper import (
    build_project_assessment_workflow_response,
)

from project_assessment_workflow.graph.workflow_options import (
    get_project_assessment_workflow_state,
    start_project_assessment_workflow,
)

from project_assessment_workflow.graph.state import (
    ProjectAssessmentState,
)


from api.roadmap_builder.schema import (
    RoadmapBuilderManagerDecisionRequest,
    RoadmapBuilderWorkflowResponse,
    StartRoadmapBuilderRequest,
)
from api.roadmap_builder.response_mapper import (
    build_roadmap_builder_workflow_response,
)
from roadmap_workflow.graph.workflow_options import (
    get_roadmap_builder_workflow_state,
    resume_roadmap_builder_workflow,
    start_roadmap_builder_workflow,
)
from roadmap_workflow.graph.state import (
    RoadmapBuilderState,
)

app = FastAPI(
    title="AI System API",
    version="1.0.0",
)

@app.get("/health")
def health_check() -> dict[str, str]:
    return {
        "status": "ok"
    }

@app.post("/roadmap-workflows",response_model=WorkflowResponse,)
def start_workflow(
    request: StartWorkflowRequest,
) -> WorkflowResponse:

    initial_state: AgentState = request.model_dump()

    workflow_output = start_roadmap_workflow(
        initial_state
    )

    return build_workflow_response(
        thread_id=workflow_output["thread_id"],
        result=workflow_output["result"],
    )

@app.post(
    "/roadmap-workflows/{thread_id}/resume",
    response_model=WorkflowResponse,
)
def resume_workflow(
    thread_id: str,
    decision: AdminDecisionRequest,
) -> WorkflowResponse:

    try:
        result = resume_roadmap_workflow(
            thread_id=thread_id,
            admin_decision=decision.model_dump(
                exclude_none=True
            ),
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=409,
            detail=str(exc),
        ) from exc

    return build_workflow_response(
        thread_id=thread_id,
        result=result,
    )

@app.get("/roadmap-workflows/{thread_id}",response_model=WorkflowResponse,)
def get_workflow(
    thread_id: str,
) -> WorkflowResponse:

    workflow_snapshot = (
        get_roadmap_workflow_state(
            thread_id
        )
    )

    state = workflow_snapshot[
        "state"
    ]

    if not state:
        raise HTTPException(
            status_code=404,
            detail="Workflow not found.",
        )

    return build_workflow_response(
        thread_id=thread_id,
        result=state,
    )

# =====================================================================
# LEARNING WORKFLOW ENDPOINTS
# =====================================================================

@app.post("/learning-workflows",response_model=LearningWorkflowResponse,)
def start_learning_workflow_endpoint(
    request: StartLearningWorkflowRequest,
) -> LearningWorkflowResponse:

    initial_state: LearningAgentState = (
        request.model_dump()
    )

    workflow_output = (
        start_learning_workflow(
            initial_state
        )
    )

    return build_learning_workflow_response(
        thread_id=workflow_output[
            "thread_id"
        ],
        result=workflow_output[
            "result"
        ],
    )


@app.post(
    "/learning-workflows/{thread_id}/resume",
    response_model=LearningWorkflowResponse,
)
def resume_learning_workflow_endpoint(
    thread_id: str,
    decision: LearningManagerDecisionRequest,
) -> LearningWorkflowResponse:

    try:
        result = resume_learning_workflow(
            thread_id=thread_id,
            manager_decision=(
                decision.model_dump(
                    exclude_none=True
                )
            ),
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=409,
            detail=str(exc),
        ) from exc

    return build_learning_workflow_response(
        thread_id=thread_id,
        result=result,
    )


@app.get(
    "/learning-workflows/{thread_id}",
    response_model=LearningWorkflowResponse,
)
def get_learning_workflow_endpoint(
    thread_id: str,
) -> LearningWorkflowResponse:

    try:
        workflow_snapshot = (
            get_learning_workflow_state(
                thread_id
            )
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=404,
            detail=str(exc),
        ) from exc

    state = workflow_snapshot[
        "state"
    ]

    return build_learning_workflow_response(
        thread_id=thread_id,
        result=state,
    )

# =====================================================================
# PROJECT ASSESSMENT ENDPOINTS
# =====================================================================

@app.post(
    "/project-assessment-workflows",
    response_model=(
        ProjectAssessmentWorkflowResponse
    ),
)
def start_project_assessment_endpoint(
    learning_thread_id: str = Form(...),
    submission_type: str = Form(...),
    repository_url: str | None = Form(
        default=None
    ),
    student_note: str | None = Form(
        default=None
    ),
    submission_file: UploadFile | None = File(
        default=None
    ),
) -> ProjectAssessmentWorkflowResponse:

    try:
        request = (
            StartProjectAssessmentRequest(
                learning_thread_id=(
                    learning_thread_id
                ),
                submission_type=(
                    submission_type
                ),
                repository_url=(
                    repository_url
                ),
                student_note=student_note,
            )
        )

    except ValidationError as exc:

        raise HTTPException(
            status_code=422,
            detail=exc.errors(
                include_url=False,
                include_context=False,
            ),
        ) from exc

    if (
        request.submission_type
        in {
            "file",
            "file and github",
        }
        and submission_file is None
    ):
        raise HTTPException(
            status_code=422,
            detail=(
                "File submission requires "
                "submission_file."
            ),
        )

    if (
        request.submission_type
        == "github"
        and submission_file is not None
    ):
        raise HTTPException(
            status_code=422,
            detail=(
                "GitHub-only submission must not "
                "contain submission_file. "
                "Use 'file and github' when "
                "submitting both."
            ),
        )

    initial_state: ProjectAssessmentState = {
        "learning_thread_id": (
            request.learning_thread_id
        ),
        "submission_type": (
            request.submission_type
        ),
        "student_note": (
            request.student_note
        ),
    }

    if request.submission_type in {
        "github",
        "file and github",
    }:

        initial_state[
            "repository_url"
        ] = request.repository_url

    if request.submission_type == "github":

        workflow_output = (
            start_project_assessment_workflow(
                initial_state
            )
        )

        return (
            build_project_assessment_workflow_response(
                thread_id=workflow_output[
                    "thread_id"
                ],
                result=workflow_output[
                    "result"
                ],
            )
        )

    file_name = (
        submission_file.filename
        if submission_file is not None
        else None
    )

    if (
        not isinstance(
            file_name,
            str,
        )
        or not file_name.strip()
    ):
        raise HTTPException(
            status_code=422,
            detail=(
                "Uploaded submission must "
                "have a file name."
            ),
        )

    file_name = file_name.strip()

    file_extension = (
        Path(
            file_name
        ).suffix
    )

    temporary_file_path: str | None = None

    try:

        with NamedTemporaryFile(
            delete=False,
            suffix=file_extension,
        ) as temporary_file:

            shutil.copyfileobj(
                submission_file.file,
                temporary_file,
            )

            temporary_file_path = (
                temporary_file.name
            )

        initial_state[
            "submission_file_name"
        ] = file_name

        initial_state[
            "submission_file_path"
        ] = temporary_file_path

        workflow_output = (
            start_project_assessment_workflow(
                initial_state
            )
        )

    finally:

        submission_file.file.close()

        if temporary_file_path:

            temporary_path = Path(
                temporary_file_path
            )

            if temporary_path.exists():
                temporary_path.unlink()

    return (
        build_project_assessment_workflow_response(
            thread_id=workflow_output[
                "thread_id"
            ],
            result=workflow_output[
                "result"
            ],
        )
    )


@app.get(
    "/project-assessment-workflows/{thread_id}",
    response_model=(
        ProjectAssessmentWorkflowResponse
    ),
)
def get_project_assessment_endpoint(
    thread_id: str,
) -> ProjectAssessmentWorkflowResponse:

    try:
        workflow_snapshot = (
            get_project_assessment_workflow_state(
                thread_id
            )
        )

    except ValueError as exc:

        raise HTTPException(
            status_code=404,
            detail=str(exc),
        ) from exc

    state = workflow_snapshot[
        "state"
    ]

    return (
        build_project_assessment_workflow_response(
            thread_id=thread_id,
            result=state,
        )
    )

# =====================================================================
# ROADMAP BUILDER WORKFLOW ENDPOINTS
# =====================================================================

@app.post(
    "/roadmap-builder-workflows",
    response_model=RoadmapBuilderWorkflowResponse,
)
def start_roadmap_builder_endpoint(
    request: StartRoadmapBuilderRequest,
) -> RoadmapBuilderWorkflowResponse:

    initial_state: RoadmapBuilderState = (
        request.model_dump()
    )

    workflow_output = (
        start_roadmap_builder_workflow(
            initial_state
        )
    )

    return (
        build_roadmap_builder_workflow_response(
            thread_id=workflow_output[
                "thread_id"
            ],
            result=workflow_output[
                "result"
            ],
        )
    )


@app.post(
    "/roadmap-builder-workflows/{thread_id}/resume",
    response_model=RoadmapBuilderWorkflowResponse,
)
def resume_roadmap_builder_endpoint(
    thread_id: str,
    decision: RoadmapBuilderManagerDecisionRequest,
) -> RoadmapBuilderWorkflowResponse:

    try:
        result = (
            resume_roadmap_builder_workflow(
                thread_id=thread_id,
                manager_decision=(
                    decision.model_dump(
                        exclude_none=True
                    )
                ),
            )
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=409,
            detail=str(exc),
        ) from exc

    return (
        build_roadmap_builder_workflow_response(
            thread_id=thread_id,
            result=result,
        )
    )


@app.get(
    "/roadmap-builder-workflows/{thread_id}",
    response_model=RoadmapBuilderWorkflowResponse,
)
def get_roadmap_builder_endpoint(
    thread_id: str,
) -> RoadmapBuilderWorkflowResponse:

    try:
        workflow_snapshot = (
            get_roadmap_builder_workflow_state(
                thread_id
            )
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=404,
            detail=str(exc),
        ) from exc

    state = workflow_snapshot[
        "state"
    ]

    return (
        build_roadmap_builder_workflow_response(
            thread_id=thread_id,
            result=state,
        )
    )