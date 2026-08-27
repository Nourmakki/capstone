from typing import Any
from api.learning.schema import (
    LearningWorkflowResponse,
)

def build_learning_workflow_response(
    thread_id: str,
    result: dict[str, Any],
) -> LearningWorkflowResponse:

    status = result.get(
        "status"
    )

    learning_data = None

    if status in {
        "awaiting_review",
        "ready",
        "rejected",
    }:
        learning_data = {
            "tutorial_result": result.get(
                "tutorial_result"
            ),
            "quiz_result": result.get(
                "quiz_result"
            ),
            "project_result": result.get(
                "project_result"
            ),
            "external_resources_result": result.get(
                "external_resources_result"
            ),
        }

    return LearningWorkflowResponse(
        thread_id=thread_id,
        status=status,
        review_stage=result.get(
            "review_stage"
        ),
        data=learning_data,
        rejection_feedback=result.get(
            "rejection_feedback"
        ),
        error=result.get(
            "error"
        ),
    )