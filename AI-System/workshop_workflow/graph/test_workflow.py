import json
from typing import Any

from workshop_workflow.graph.state import (
    WorkshopAgentState,
)
from workshop_workflow.graph.workflow_options import (
    continue_workshop_workflow,
    get_workshop_workflow_state,
    resume_workshop_workflow,
    start_workshop_workflow,
)


TEST_STATE: WorkshopAgentState = {
    "roadmap_topic": "AI engineering"
}


# =====================================================================
# PRINT HELPERS
# =====================================================================

def print_json(
    value: Any,
) -> None:
    print(
        json.dumps(
            value,
            indent=2,
            ensure_ascii=False,
            default=str,
        )
    )


def print_workflow_summary(
    thread_id: str,
    state: dict[str, Any],
    next_nodes: list[str] | None = None,
) -> None:
    print_json({
        "thread_id": thread_id,
        "status": state.get(
            "status"
        ),
        "review_stage": state.get(
            "review_stage"
        ),
        "roadmap_topic": state.get(
            "roadmap_topic"
        ),
        "student_location": state.get(
            "student_location"
        ),
        "workshops": state.get(
            "workshops",
            [],
        ),
        "approved_workshops": state.get(
            "approved_workshops",
            [],
        ),
        "generation_attempts": state.get(
            "generation_attempts"
        ),
        "rejection_feedback": state.get(
            "rejection_feedback"
        ),
        "error": state.get(
            "error"
        ),
        "next_nodes": next_nodes,
    })


# =====================================================================
# START LIVE WORKFLOW TEST
# =====================================================================

def start_test() -> str:
    print(
        "\nStarting Workshop Workflow...\n"
    )

    response = start_workshop_workflow(
        TEST_STATE
    )

    thread_id = response[
        "thread_id"
    ]

    saved = get_workshop_workflow_state(
        thread_id
    )

    print_workflow_summary(
        thread_id=thread_id,
        state=saved["state"],
        next_nodes=saved[
            "next_nodes"
        ],
    )

    status = saved["state"].get(
        "status"
    )

    if status == "awaiting_review":
        if not saved["state"].get(
            "workshops"
        ):
            raise AssertionError(
                "Workflow reached review without workshops."
            )

        if "review_workshops" not in saved[
            "next_nodes"
        ]:
            raise AssertionError(
                "Workshop review interrupt was not saved."
            )

        print(
            "\nWORKFLOW TEST PASSED."
        )
        print(
            "The proposed workshops are waiting for manager review."
        )
        print(
            "\nSAVE THIS THREAD ID FOR APPROVE OR REJECT:\n"
        )
        print(
            thread_id
        )

    elif status == "ready_for_commit":
        if saved["state"].get(
            "workshops"
        ):
            raise AssertionError(
                "A non-empty proposal skipped manager review."
            )

        print(
            "\nWORKFLOW TEST PASSED WITH NO RESULTS."
        )
        print(
            "No suitable upcoming workshop was found, so no review was required."
        )

    elif status == "failed":
        raise AssertionError(
            "Workshop Workflow failed: "
            f"{saved['state'].get('error')}"
        )

    else:
        raise AssertionError(
            "Unexpected workflow status: "
            f"{status}"
        )

    return thread_id


# =====================================================================
# READ A SAVED WORKFLOW
# =====================================================================

def state_test(
    thread_id: str,
) -> None:
    saved = get_workshop_workflow_state(
        thread_id
    )

    print_workflow_summary(
        thread_id=thread_id,
        state=saved["state"],
        next_nodes=saved[
            "next_nodes"
        ],
    )


# =====================================================================
# APPROVE
# =====================================================================

def approve_test(
    thread_id: str,
) -> None:
    before = get_workshop_workflow_state(
        thread_id
    )["state"]

    result = resume_workshop_workflow(
        thread_id=thread_id,
        manager_decision={
            "action": "approve",
        },
    )

    if result.get("status") != "ready_for_commit":
        raise AssertionError(
            "Approved workflow must become ready_for_commit."
        )

    if result.get("approved_workshops") != before.get(
        "workshops"
    ):
        raise AssertionError(
            "Approval did not preserve all proposed workshops."
        )

    saved = get_workshop_workflow_state(
        thread_id
    )

    if saved["next_nodes"]:
        raise AssertionError(
            "Approved workflow must be terminal."
        )

    print_workflow_summary(
        thread_id=thread_id,
        state=saved["state"],
        next_nodes=saved[
            "next_nodes"
        ],
    )

    print(
        "\nAPPROVAL TEST PASSED."
    )


# =====================================================================
# APPROVE SELECTED WORKSHOPS
# =====================================================================

def approve_selected_test(
    thread_id: str,
    approved_workshop_links: (
        list[str] | None
    ) = None,
) -> None:
    before = get_workshop_workflow_state(
        thread_id
    )["state"]

    proposed_workshops = before.get(
        "workshops",
        [],
    )

    if not proposed_workshops:
        raise ValueError(
            "Workflow does not contain proposed workshops."
        )

    # When no list is supplied, approve only the first workshop. This makes
    # the manual test convenient while still exercising partial approval.
    if approved_workshop_links is None:
        approved_workshop_links = [
            proposed_workshops[0][
                "link"
            ]
        ]

    result = resume_workshop_workflow(
        thread_id=thread_id,
        manager_decision={
            "action": "approve",
            "data": {
                "approved_workshop_links": (
                    approved_workshop_links
                ),
            },
        },
    )

    expected_links = set(
        approved_workshop_links
    )

    expected_workshops = [
        workshop
        for workshop in proposed_workshops
        if workshop["link"]
        in expected_links
    ]

    if result.get("status") != "ready_for_commit":
        raise AssertionError(
            "Selective approval must become ready_for_commit."
        )

    if result.get("approved_workshops") != expected_workshops:
        raise AssertionError(
            "Only the selected workshops should be approved."
        )

    if result.get("workshops") != proposed_workshops:
        raise AssertionError(
            "Selective approval changed the original proposal."
        )

    saved = get_workshop_workflow_state(
        thread_id
    )

    if saved["next_nodes"]:
        raise AssertionError(
            "Selectively approved workflow must be terminal."
        )

    print_workflow_summary(
        thread_id=thread_id,
        state=saved["state"],
        next_nodes=saved[
            "next_nodes"
        ],
    )

    print(
        "\nSELECTIVE APPROVAL TEST PASSED."
    )


# =====================================================================
# REJECT
# =====================================================================

def reject_test(
    thread_id: str,
) -> None:
    feedback = (
        "The proposed workshops are not suitable for publication."
    )

    result = resume_workshop_workflow(
        thread_id=thread_id,
        manager_decision={
            "action": "reject",
            "feedback": feedback,
        },
    )

    if result.get("status") != "rejected":
        raise AssertionError(
            "Rejected workflow must become rejected."
        )

    if result.get("approved_workshops"):
        raise AssertionError(
            "Rejected workflow must not contain approved workshops."
        )

    if result.get("rejection_feedback") != feedback:
        raise AssertionError(
            "Rejected workflow did not preserve manager feedback."
        )

    saved = get_workshop_workflow_state(
        thread_id
    )

    if saved["next_nodes"]:
        raise AssertionError(
            "Rejected workflow must be terminal."
        )

    print_workflow_summary(
        thread_id=thread_id,
        state=saved["state"],
        next_nodes=saved[
            "next_nodes"
        ],
    )

    print(
        "\nREJECTION TEST PASSED."
    )


# =====================================================================
# INVALID REGENERATION DECISION
# =====================================================================

def regeneration_is_disabled_test(
    thread_id: str,
) -> None:
    try:
        resume_workshop_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "regenerate",
                "feedback": (
                    "Find different workshops."
                ),
            },
        )

    except ValueError as error:
        print(
            "\nREGENERATION DISABLED TEST PASSED."
        )
        print(
            str(error)
        )
        return

    raise AssertionError(
        "Workshop Workflow unexpectedly accepted regeneration."
    )


# =====================================================================
# RECOVER AN INTERRUPTED GENERATION
# =====================================================================

def continue_test(
    thread_id: str,
) -> None:
    before = get_workshop_workflow_state(
        thread_id
    )

    print_json({
        "current_status": before["state"].get(
            "status"
        ),
        "pending_nodes": before[
            "next_nodes"
        ],
    })

    result = continue_workshop_workflow(
        thread_id
    )

    print_workflow_summary(
        thread_id=thread_id,
        state=result,
    )


if __name__ == "__main__":
    start_test()
