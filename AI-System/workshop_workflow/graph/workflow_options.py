from typing import Any
from uuid import uuid4

from langgraph.types import Command

from graph.postgresSaver_connection import (
    open_checkpointer,
)
from workshop_workflow.graph.state import (
    WorkshopAgentState,
)
from workshop_workflow.graph.workflow import (
    build_workshop_workflow,
    require_manager_decision,
)


# =====================================================================
# THREAD CONFIGURATION
# =====================================================================

def create_thread_config(
    thread_id: str,
) -> dict[str, Any]:
    if (
        not isinstance(
            thread_id,
            str,
        )
        or not thread_id.strip()
    ):
        raise ValueError(
            "thread_id is required."
        )

    return {
        "configurable": {
            "thread_id": (
                thread_id.strip()
            ),
        }
    }


# =====================================================================
# START WORKFLOW
# =====================================================================

def start_workshop_workflow(
    initial_state: WorkshopAgentState,
) -> dict[str, Any]:
    """
    Start one Workshop Workflow and persist it in PostgreSQL.

    When workshops are found, execution pauses at manager review. When no
    suitable workshop is found, the workflow completes as an empty no-op.
    """

    thread_id = str(
        uuid4()
    )

    config = create_thread_config(
        thread_id
    )

    complete_initial_state: WorkshopAgentState = {
        **initial_state,
        "workflow_id": thread_id,
        "workshops": [],
        "approved_workshops": [],
        "regeneration_feedback": None,
        "generation_attempts": 0,
        "status": "starting",
        "review_stage": None,
        "rejection_feedback": None,
        "error": None,
    }

    with open_checkpointer() as checkpointer:
        graph = build_workshop_workflow(
            checkpointer=checkpointer
        )

        result = graph.invoke(
            complete_initial_state,
            config=config,
        )

    return {
        "thread_id": thread_id,
        "result": result,
    }


# =====================================================================
# RESUME WORKFLOW AFTER MANAGER REVIEW
# =====================================================================

def resume_workshop_workflow(
    thread_id: str,
    manager_decision: dict[str, Any],
) -> dict[str, Any]:
    decision = require_manager_decision(
        manager_decision
    )

    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        graph = build_workshop_workflow(
            checkpointer=checkpointer
        )

        snapshot = graph.get_state(
            config
        )

        if not snapshot.values:
            raise ValueError(
                "Workflow not found."
            )

        status = snapshot.values.get(
            "status"
        )

        if status != "awaiting_review":
            raise ValueError(
                "Workflow is not awaiting manager review. "
                f"Current status is '{status}'."
            )

        if (
            snapshot.values.get(
                "review_stage"
            )
            != "workshops"
        ):
            raise ValueError(
                "Workflow is not waiting at the workshops review stage."
            )

        if "review_workshops" not in snapshot.next:
            raise ValueError(
                "Workshop review checkpoint is not resumable."
            )

        result = graph.invoke(
            Command(
                resume=decision
            ),
            config=config,
        )

    return result


# =====================================================================
# GET WORKFLOW STATE
# =====================================================================

def get_workshop_workflow_state(
    thread_id: str,
) -> dict[str, Any]:
    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        graph = build_workshop_workflow(
            checkpointer=checkpointer
        )

        snapshot = graph.get_state(
            config
        )

    if not snapshot.values:
        raise ValueError(
            "Workflow not found."
        )

    return {
        "thread_id": thread_id,
        "state": snapshot.values,
        "next_nodes": list(
            snapshot.next
        ),
        "created_at": snapshot.created_at,
    }


# =====================================================================
# RECOVER INTERRUPTED GENERATION
# =====================================================================

def continue_workshop_workflow(
    thread_id: str,
) -> dict[str, Any]:
    """
    Continue a generation step interrupted by a process restart.

    This is not used for manager review. Review checkpoints must be resumed
    through resume_workshop_workflow with an approve or reject decision.
    """

    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        graph = build_workshop_workflow(
            checkpointer=checkpointer
        )

        snapshot = graph.get_state(
            config
        )

        if not snapshot.values:
            raise ValueError(
                "Workflow not found."
            )

        status = snapshot.values.get(
            "status"
        )

        if status == "awaiting_review":
            raise ValueError(
                "Workflow is awaiting manager review. "
                "Use resume_workshop_workflow instead."
            )

        if not snapshot.next:
            raise ValueError(
                "Workflow has already reached a terminal state."
            )

        result = graph.invoke(
            None,
            config=config,
        )

    return result
