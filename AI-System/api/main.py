from fastapi import FastAPI, HTTPException
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