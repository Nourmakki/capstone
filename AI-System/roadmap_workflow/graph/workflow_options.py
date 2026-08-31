from typing import Any
from uuid import uuid4

from langgraph.types import Command

from graph.postgresSaver_connection import (
    open_checkpointer,
)

from .state import RoadmapBuilderState
from .workflow import (
    build_roadmap_builder_workflow,
    get_current_roadmap_package,
    require_manager_decision,
    validate_current_roadmap_package,
    validate_scope_result,
)


def create_thread_config(
    thread_id: str,
) -> dict[str, Any]:
    return {
        "configurable": {
            "thread_id": (
                thread_id
            ),
        }
    }


def start_roadmap_builder_workflow(
    initial_state: RoadmapBuilderState,
) -> dict[str, Any]:
    thread_id = str(
        uuid4()
    )

    config = (
        create_thread_config(
            thread_id
        )
    )

    complete_initial_state: (
        RoadmapBuilderState
    ) = {
        **initial_state,
        "workflow_id": thread_id,
        "roadmap_packages": [],
        "approved_roadmap_packages": [],
        "current_roadmap_review_index": 0,
        "scope_generation_attempts": 0,
        "roadmap_generation_attempts": 0,
        "scope_regeneration_feedback": None,
        "roadmap_regeneration_feedback": None,
        "status": "generating_scope",
        "review_stage": None,
        "rejection_feedback": None,
        "error": None,
    }

    with open_checkpointer() as checkpointer:
        graph = (
            build_roadmap_builder_workflow(
                checkpointer=(
                    checkpointer
                )
            )
        )

        result = graph.invoke(
            complete_initial_state,
            config=config,
        )

    return {
        "thread_id": thread_id,
        "result": result,
    }


def validate_decision_for_review_stage(
    decision: dict[str, Any],
    state: RoadmapBuilderState,
) -> dict[str, Any]:
    review_stage = state.get(
        "review_stage"
    )

    action = decision[
        "action"
    ]

    if review_stage not in {
        "scope_review",
        "roadmap_review",
    }:
        raise ValueError(
            "Workflow is not waiting at a valid review stage."
        )

    if action in {
        "approve",
        "reject",
    }:
        return decision

    decision_stage = decision[
        "stage"
    ]

    if review_stage == "scope_review":
        if (
            decision_stage
            != "scope"
        ):
            raise ValueError(
                "The workflow is currently waiting for scope review."
            )

        if action == "edit":
            validated_scope = (
                validate_scope_result(
                    decision[
                        "data"
                    ]
                )
            )

            return {
                **decision,
                "data": (
                    validated_scope
                ),
            }

        return decision

    if (
        decision_stage
        != "roadmap"
    ):
        raise ValueError(
            "The workflow is currently waiting for roadmap review."
        )

    if action == "edit":
        current_package = (
            get_current_roadmap_package(
                state
            )
        )

        validated_package = (
            validate_current_roadmap_package(
                data=decision[
                    "data"
                ],
                expected_package=(
                    current_package
                ),
            )
        )

        return {
            **decision,
            "data": (
                validated_package
            ),
        }

    return decision


def resume_roadmap_builder_workflow(
    thread_id: str,
    manager_decision: dict[str, Any],
) -> dict[str, Any]:
    validated_decision = (
        require_manager_decision(
            manager_decision
        )
    )

    config = (
        create_thread_config(
            thread_id
        )
    )

    with open_checkpointer() as checkpointer:
        graph = (
            build_roadmap_builder_workflow(
                checkpointer=(
                    checkpointer
                )
            )
        )

        snapshot = graph.get_state(
            config
        )

        if not snapshot.values:
            raise ValueError(
                "Workflow not found."
            )

        state = snapshot.values

        status = state.get(
            "status"
        )

        if (
            status
            != "awaiting_review"
        ):
            raise ValueError(
                "Workflow cannot accept a manager decision "
                f"because its status is '{status}'."
            )

        validated_decision = (
            validate_decision_for_review_stage(
                decision=(
                    validated_decision
                ),
                state=state,
            )
        )

        result = graph.invoke(
            Command(
                resume=(
                    validated_decision
                )
            ),
            config=config,
        )

    return result


def get_roadmap_builder_workflow_state(
    thread_id: str,
) -> dict[str, Any]:
    config = (
        create_thread_config(
            thread_id
        )
    )

    with open_checkpointer() as checkpointer:
        graph = (
            build_roadmap_builder_workflow(
                checkpointer=(
                    checkpointer
                )
            )
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
        "created_at": (
            snapshot.created_at
        ),
    }