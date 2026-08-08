# Starts, resumes, and reads workflows

from typing import Any
from uuid import uuid4

from langgraph.types import Command

from .postgresSaver_connection import open_checkpointer
from .state import AgentState
from .workflow import build_roadmap_workflow


def create_thread_config(thread_id: str,) -> dict[str, Any]:
    """
    Build the LangGraph configuration for one workflow.
    """

    return {
        "configurable": {
            "thread_id": thread_id,
        }
    }


def start_roadmap_workflow(
    initial_state: AgentState,
) -> dict[str, Any]:
    """
    Start a new Trend -> Node -> Placement workflow.

    It normally pauses at the trend review interrupt.
    """

    thread_id = str(uuid4())

    config = create_thread_config(
        thread_id
    )

    complete_initial_state: AgentState = {
        **initial_state,
        "workflow_id": thread_id,
        "status": "running_trend_agent",
        "review_stage": None,
        "error": None,
        "rejection_feedback": None,
    }

    with open_checkpointer() as checkpointer:
        graph = build_roadmap_workflow(
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


def resume_roadmap_workflow(
    thread_id: str,
    admin_decision: dict[str, Any],
) -> dict[str, Any]:
    """
    Resume a paused workflow with an admin decision.
    """

    action = admin_decision.get("action")

    if action not in {
        "approve",
        "edit",
        "regenerate",
        "reject",
    }:
        raise ValueError(
            "The admin decision action must be "
            "'approve', 'edit', 'regenerate', or 'reject'."
        )

    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        graph = build_roadmap_workflow(
            checkpointer=checkpointer
        )
        snapshot = graph.get_state(config)

        status = snapshot.values.get(
            "status"
        )

        if status in {
            "rejected",
            "ready_for_commit",
        }:
            raise ValueError(
                f"Workflow cannot be resumed "
                f"because its status is '{status}'."
            )

        result = graph.invoke(
            Command(
                resume=admin_decision
            ),
            config=config,
        )

    return result


def get_roadmap_workflow_state(
    thread_id: str,
) -> dict[str, Any]:
    """
    Load the most recent saved state of a workflow.
    """

    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        graph = build_roadmap_workflow(
            checkpointer=checkpointer
        )

        snapshot = graph.get_state(
            config
        )

    return {
        "thread_id": thread_id,
        "state": snapshot.values,
        "next_nodes": list(snapshot.next),
        "created_at": snapshot.created_at,
    }