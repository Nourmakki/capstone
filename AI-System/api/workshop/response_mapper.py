from typing import Any
from api.workshop.schema import (
    WorkshopWorkflowResponse,
)


def build_workshop_workflow_response(
    thread_id: str,
    result: dict[str, Any],
) -> WorkshopWorkflowResponse:
    """
    Convert the internal Workshop Workflow state into
    the API response returned to Laravel.

    While the workflow is waiting for manager review,
    Laravel receives the proposed workshops.

    When the workflow is ready for commit,
    Laravel receives only the manager-approved workshops.
    """

    status = result.get(
        "status"
    )

    workshop_data = None

    # =================================================================
    # PROPOSED WORKSHOPS
    # =================================================================

    if status in {
        "awaiting_review",
        "rejected",
    }:
        workshop_data = {
            "workshops": result.get(
                "workshops",
                [],
            ),
        }

    # =================================================================
    # APPROVED WORKSHOPS
    # =================================================================

    elif status == "ready_for_commit":
        approved_workshops = (
            result.get(
                "approved_workshops"
            )
        )

        if approved_workshops is None:
            raise ValueError(
                "Approved workshops are missing "
                "from a ready-for-commit workflow."
            )

        workshop_data = {
            "workshops": (
                approved_workshops
            ),
        }

    # =================================================================
    # RESPONSE
    # =================================================================

    return WorkshopWorkflowResponse(
        thread_id=thread_id,

        status=status,

        review_stage=result.get(
            "review_stage"
        ),

        data=workshop_data,

        rejection_feedback=result.get(
            "rejection_feedback"
        ),

        generation_attempts=result.get(
            "generation_attempts",
            0,
        ),

        error=result.get(
            "error"
        ),
    )