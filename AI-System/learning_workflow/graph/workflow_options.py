# Starts, resumes, and reads Learning Agent workflows.
from typing import Any
from uuid import uuid4
from langgraph.types import Command
from graph.postgresSaver_connection import (open_checkpointer,)
from .state import (LearningAgentState,)
from .workflow import (
    build_learning_workflow,
    require_manager_decision,
    require_edited_result,
    validate_edited_quiz,
    validate_edited_project,
    validate_edited_external_resources,
)

# =====================================================================
# THREAD CONFIGURATION
# =====================================================================
def create_thread_config(
    thread_id: str,
) -> dict[str, Any]:
    """
    Build the LangGraph configuration for one workflow.
    """

    return {
        "configurable": {
            "thread_id": thread_id,
        }
    }

# =====================================================================
# START WORKFLOW
# =====================================================================

def start_learning_workflow(
    initial_state: LearningAgentState,
) -> dict[str, Any]:
    """
    Start a new learning-material generation workflow.

    The workflow:
    Tutorial
        ->
    Quiz + Project + External Resources
        ->
    Manager Review

    It normally pauses at the manager review interrupt.
    """

    thread_id = str(uuid4())
    config = create_thread_config(thread_id)
    complete_initial_state: LearningAgentState = {
        **initial_state,
        "workflow_id": thread_id,
        "status": "running_tutorial_agent",
        "review_stage": None,
        "rejection_feedback": None,
        "regeneration_feedback": None,
        "error": None,
    }
    with open_checkpointer() as checkpointer:

        graph = build_learning_workflow(
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


def validate_manager_edit(
    state: LearningAgentState,
    decision: dict[str, Any],
) -> None:

    if decision["action"] != "edit":
        return

    stage = decision["stage"]

    if stage == "quiz":
        validate_edited_quiz(
            state,
            decision,
        )
        return

    if stage == "project":
        edited_result = require_edited_result(
            decision,
            "project_result",
        )

        validate_edited_project(
            state,
            edited_result,
        )

        return

    edited_result = require_edited_result(
        decision,
        "external_resources_result",
    )

    validate_edited_external_resources(
        state,
        edited_result,
    )

# =====================================================================
# RESUME WORKFLOW
# =====================================================================

def resume_learning_workflow(
    thread_id: str,
    manager_decision: dict[str, Any],
) -> dict[str, Any]:

    manager_decision = (
        require_manager_decision(
            manager_decision
        )
    )

    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:

        graph = build_learning_workflow(
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
                "Workflow is not awaiting "
                "manager review."
            )

        validate_manager_edit(
            snapshot.values,
            manager_decision,
        )

        result = graph.invoke(
            Command(
                resume=manager_decision
            ),
            config=config,
        )

    return result
# =====================================================================
# GET WORKFLOW STATE
# =====================================================================

def get_learning_workflow_state(
    thread_id: str,
) -> dict[str, Any]:
    """
    Load the most recent saved state of a
    Learning Agent workflow.
    """
    config = create_thread_config(thread_id)
    with open_checkpointer() as checkpointer:
        graph = build_learning_workflow(
            checkpointer=checkpointer
        )
        snapshot = graph.get_state(config)

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
        "created_at": (
            snapshot.created_at
        ),
    }