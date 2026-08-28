from typing import Any
from langgraph.graph import (
    END,
    START,
    StateGraph,
)
from learning_workflow.schema import (
    ProjectAgentResult,
)
from project_assessment_workflow.agents.project_assessment_agent import (
    run_project_assessment_agent,
)
from project_assessment_workflow.tools.submission_processor import (
    process_github_submission,
    process_submission,
)
from project_assessment_workflow.graph.state import (
    ProjectAssessmentState,
)
from learning_workflow.graph.workflow_options import (
    get_learning_workflow_state,
)

from learning_workflow.graph.state import ProjectResultState
# =====================================================================
# INPUT VALIDATION
# =====================================================================
def validate_project_assessment_input(
    state: ProjectAssessmentState,
) -> ProjectResultState:

    learning_thread_id = state.get(
        "learning_thread_id"
    )

    if not learning_thread_id:
        raise ValueError(
            "learning_thread_id is required."
        )

    submission_type = state.get(
        "submission_type"
    )

    if submission_type not in {
        "file",
        "github",
        "file and github",
    }:
        raise ValueError(
            "submission_type must be "
            "'file', 'github', or "
            "'file and github'."
        )

    if submission_type in {
        "file",
        "file and github",
    }:

        submission_file_path = state.get(
            "submission_file_path"
        )

        if not submission_file_path:
            raise ValueError(
                "File submission requires "
                "submission_file_path."
            )

    if submission_type in {
        "github",
        "file and github",
    }:

        repository_url = state.get(
            "repository_url"
        )

        if not repository_url:
            raise ValueError(
                "GitHub submission requires "
                "repository_url."
            )

    learning_snapshot = (
        get_learning_workflow_state(
            learning_thread_id
        )
    )

    learning_state = (
        learning_snapshot[
            "state"
        ]
    )

    if (
        learning_state.get(
            "status"
        )
        != "ready"
    ):
        raise ValueError(
            "Learning workflow must be ready "
            "before project assessment."
        )

    project_result = (
        learning_state.get(
            "project_result"
        )
    )

    if project_result is None:
        raise ValueError(
            "Learning workflow does not "
            "contain a project result."
        )

    validated_project_result = (
        ProjectAgentResult.model_validate(
            project_result
        )
    )

    return (
        validated_project_result.model_dump()
    )


# =====================================================================
# PREPARE PROJECT ASSESSMENT
# =====================================================================
def prepare_project_assessment(
    state: ProjectAssessmentState,
) -> dict[str, Any]:

    try:
        validated_project = (
            validate_project_assessment_input(
                state
            )
        )

    except Exception as error:
        return {
            "status": "failed",
            "error": str(error),
        }

    return {
        "project_result": validated_project,
        "status": (
            "running_project_assessment_agent"
        ),
        "error": None,
    }


# =====================================================================
# PREPARATION ROUTING
# =====================================================================

def route_after_preparation(
    state: ProjectAssessmentState,
) -> str:

    if state.get("status") == "failed":
        return "failed"

    return "assess"


# =====================================================================
# PROCESS STUDENT SUBMISSION
# =====================================================================

def build_submission_evidence(
    state: ProjectAssessmentState,
) -> dict[str, Any]:

    submission_type = state[
        "submission_type"
    ]

    student_note = state.get(
        "student_note"
    )

    if submission_type == "file":

        return process_submission(
            file_path=state[
                "submission_file_path"
            ],
            student_note=student_note,
        )

    if submission_type == "github":

        return process_github_submission(
            repository_url=state[
                "repository_url"
            ],
            student_note=student_note,
        )

    file_evidence = (
        process_submission(
            file_path=state[
                "submission_file_path"
            ],
            student_note=student_note,
        )
    )

    github_evidence = (
        process_github_submission(
            repository_url=state[
                "repository_url"
            ],
            student_note=student_note,
        )
    )

    return {
        "submission_file_name": (
            state.get(
                "submission_file_name"
            )
            or file_evidence.get(
                "submission_file_name"
            )
        ),

        "submission_extension": (
            file_evidence.get(
                "submission_extension"
            )
        ),

        "student_note": student_note,

        "text_files": (
            file_evidence.get(
                "text_files",
                [],
            )
            + github_evidence.get(
                "text_files",
                [],
            )
        ),

        "visual_files": (
            file_evidence.get(
                "visual_files",
                [],
            )
            + github_evidence.get(
                "visual_files",
                [],
            )
        ),

        "processing_notes": (
            file_evidence.get(
                "processing_notes",
                [],
            )
            + github_evidence.get(
                "processing_notes",
                [],
            )
        ),

        "repository_url": (
            github_evidence.get(
                "repository_url"
            )
        ),

        "repository_full_name": (
            github_evidence.get(
                "repository_full_name"
            )
        ),

        "repository_default_branch": (
            github_evidence.get(
                "repository_default_branch"
            )
        ),
    }

# =====================================================================
# RUN PROJECT ASSESSMENT
# =====================================================================

def assess_project_submission(
    state: ProjectAssessmentState,
) -> dict[str, Any]:

    try:
        submission_evidence = (
            build_submission_evidence(
                state
            )
        )

        assessment_output = (
            run_project_assessment_agent(
                {
                    "project_result": (
                        state["project_result"]
                    ),
                    "submission_evidence": (
                        submission_evidence
                    ),
                }
            )
        )

    except Exception as error:
        return {
            "status": "failed",
            "error": str(error),
        }

    update: dict[str, Any] = {
        "project_assessment_result": (
            assessment_output[
                "project_assessment_result"
            ]
        ),
        "assessment_score": (
            assessment_output[
                "assessment_score"
            ]
        ),
        "status": "ready",
        "error": None,
    }

    submission_file_name = (
        submission_evidence.get(
            "submission_file_name"
        )
    )

    if submission_file_name:
        update[
            "submission_file_name"
        ] = submission_file_name

    repository_default_branch = (
        submission_evidence.get(
            "repository_default_branch"
        )
    )

    if repository_default_branch:
        update[
            "repository_default_branch"
        ] = repository_default_branch

    return update


# =====================================================================
# BUILD WORKFLOW
# =====================================================================

def build_project_assessment_workflow():

    workflow = StateGraph(ProjectAssessmentState)
    workflow.add_node(
        "prepare_project_assessment",
        prepare_project_assessment,
    )
    workflow.add_node(
        "assess_project_submission",
        assess_project_submission,
    )

    workflow.add_edge(
        START,
        "prepare_project_assessment",
    )

    workflow.add_conditional_edges(
        "prepare_project_assessment",
        route_after_preparation,
        {
            "assess": (
                "assess_project_submission"
            ),
            "failed": END,
        },
    )

    workflow.add_edge(
        "assess_project_submission",
        END,
    )

    return workflow