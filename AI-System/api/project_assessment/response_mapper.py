from typing import Any
from api.project_assessment.schema import (
    ProjectAssessmentWorkflowResponse,
)

def build_project_assessment_workflow_response(
    thread_id: str,
    result: dict[str, Any],
) -> ProjectAssessmentWorkflowResponse:

    status = result.get("status")
    assessment_data = None
    if status == "ready_for_commit":
        assessment_data = {
            "learning_thread_id": (
                result.get(
                    "learning_thread_id"
                )
            ),

            "submission": {
                "submission_type": (
                    result.get(
                        "submission_type"
                    )
                ),

                "submission_file_name": (
                    result.get(
                        "submission_file_name"
                    )
                ),

                "repository_url": (
                    result.get(
                        "repository_url"
                    )
                ),

                "repository_default_branch": (
                    result.get(
                        "repository_default_branch"
                    )
                ),
            },

            "project_assessment_result": (
                result.get(
                    "project_assessment_result"
                )
            ),

            "assessment_score": (
                result.get(
                    "assessment_score"
                )
            ),
        }

    return ProjectAssessmentWorkflowResponse(
        thread_id=thread_id,
        status=status,
        data=assessment_data,
        error=result.get(
            "error"
        ),
    )