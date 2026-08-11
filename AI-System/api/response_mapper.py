from typing import Any
from api.schema import WorkflowResponse

def build_workflow_response(
    thread_id: str,
    result: dict[str, Any],
) -> WorkflowResponse:

    status = result.get("status")

    proposal_data = None

    if status in {
        "awaiting_review",
        "ready_for_commit",
        "rejected",
    }:
        proposal_data = {
            "trends": result.get(
                "trends",
                [],
            ),
            "node_suggestions": result.get(
                "node_suggestions",
                [],
            ),
            "placement_suggestions": result.get(
                "placement_suggestions",
                [],
            ),
            "existing_edges_to_remove": result.get(
                "existing_edges_to_remove",
                [],
            ),
        }

    return WorkflowResponse(
        thread_id=thread_id,
        status=status,
        review_stage=result.get(
            "review_stage"
        ),
        data=proposal_data,
        rejection_feedback=result.get(
            "rejection_feedback"
        ),
        error=result.get(
            "error"
        ),
    )