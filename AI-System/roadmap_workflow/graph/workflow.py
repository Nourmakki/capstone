from copy import deepcopy
from typing import Any

from langgraph.graph import END, START, StateGraph
from langgraph.types import Command, interrupt

from roadmap_workflow.agents.roadmap_scope_agent import (
    run_roadmap_scope_agent,
)
from roadmap_workflow.roadmap_generation import (
    generate_roadmap_package,
    regenerate_roadmap_package,
)
from roadmap_workflow.schema import (
    GeneratedPathRoadmap,
    RoadmapScopeResult,
)
from .state import RoadmapBuilderState


# =====================================================================
# MANAGER DECISION VALIDATION
# =====================================================================

def require_manager_decision(
    manager_decision: dict[str, Any],
) -> dict[str, Any]:
    if not isinstance(
        manager_decision,
        dict,
    ):
        raise ValueError(
            "Manager decision must be an object."
        )

    action = manager_decision.get(
        "action"
    )

    if action not in {
        "approve",
        "edit",
        "regenerate",
        "reject",
    }:
        raise ValueError(
            "Manager action must be approve, edit, regenerate, or reject."
        )

    stage = manager_decision.get(
        "stage"
    )

    data = manager_decision.get(
        "data"
    )

    feedback = manager_decision.get(
        "feedback"
    )

    if action == "approve":
        if stage is not None:
            raise ValueError(
                "Approve must not contain a stage."
            )

        if data is not None:
            raise ValueError(
                "Approve must not contain data."
            )

        if feedback is not None:
            raise ValueError(
                "Approve must not contain feedback."
            )

    elif action == "reject":
        if stage is not None:
            raise ValueError(
                "Reject must not contain a stage."
            )

        if data is not None:
            raise ValueError(
                "Reject must not contain data."
            )

        if feedback is not None:
            if (
                not isinstance(
                    feedback,
                    str,
                )
                or not feedback.strip()
            ):
                raise ValueError(
                    "Reject feedback must be a non-empty string when provided."
                )

            feedback = feedback.strip()

    elif action == "edit":
        if stage not in {
            "scope",
            "roadmap",
        }:
            raise ValueError(
                "Edit must contain stage='scope' or stage='roadmap'."
            )

        if not isinstance(
            data,
            dict,
        ):
            raise ValueError(
                "Edit must contain complete edited data."
            )

        if feedback is not None:
            raise ValueError(
                "Edit must not contain feedback."
            )

    elif action == "regenerate":
        if stage not in {
            "scope",
            "roadmap",
        }:
            raise ValueError(
                "Regenerate must contain stage='scope' or stage='roadmap'."
            )

        if data is not None:
            raise ValueError(
                "Regenerate must not contain data."
            )

        if (
            not isinstance(
                feedback,
                str,
            )
            or not feedback.strip()
        ):
            raise ValueError(
                "Regenerate must contain non-empty feedback."
            )

        feedback = feedback.strip()

    return {
        "action": action,
        "stage": stage,
        "data": data,
        "feedback": feedback,
    }


# =====================================================================
# VALIDATION HELPERS
# =====================================================================

def validate_scope_result(
    data: dict[str, Any],
) -> dict[str, Any]:
    return (
        RoadmapScopeResult
        .model_validate(
            data
        )
        .model_dump()
    )


def get_current_roadmap_package(
    state: RoadmapBuilderState,
) -> dict[str, Any]:
    packages = state.get(
        "roadmap_packages",
        [],
    )

    current_index = state.get(
        "current_roadmap_review_index",
        0,
    )

    if (
        current_index < 0
        or current_index >= len(packages)
    ):
        raise ValueError(
            "Current roadmap review index is invalid."
        )

    return packages[
        current_index
    ]


def validate_current_roadmap_package(
    data: dict[str, Any],
    expected_package: dict[str, Any],
) -> dict[str, Any]:
    validated = (
        GeneratedPathRoadmap
        .model_validate(
            data
        )
        .model_dump()
    )

    if (
        validated["path_id"]
        != expected_package["path_id"]
    ):
        raise ValueError(
            "Manager edit cannot change the roadmap path_id."
        )

    if (
        validated["path_title"].casefold()
        != expected_package[
            "path_title"
        ].casefold()
    ):
        raise ValueError(
            "Manager edit cannot change the roadmap path_title."
        )

    return validated


# =====================================================================
# SCOPE GENERATION
# =====================================================================

def generate_scope(
    state: RoadmapBuilderState,
) -> Command:
    try:
        roadmap_topic = state[
            "roadmap_topic"
        ]

        regeneration_feedback = (
            state.get(
                "scope_regeneration_feedback"
            )
        )

        if regeneration_feedback:
            scope_result = (
                run_roadmap_scope_agent(
                    roadmap_topic=roadmap_topic,
                    existing_scope=state.get(
                        "scope_result"
                    ),
                    regeneration_feedback=(
                        regeneration_feedback
                    ),
                )
            )

        else:
            scope_result = (
                run_roadmap_scope_agent(
                    roadmap_topic=roadmap_topic
                )
            )

        validated_scope = (
            validate_scope_result(
                scope_result
            )
        )

        attempts = (
            state.get(
                "scope_generation_attempts",
                0,
            )
            + 1
        )

        return Command(
            update={
                "scope_result": (
                    validated_scope
                ),
                "scope_generation_attempts": (
                    attempts
                ),
                "scope_regeneration_feedback": None,
                "status": "awaiting_review",
                "review_stage": "scope_review",
                "error": None,
            },
            goto="review_scope",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Scope generation failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# SCOPE REVIEW
# =====================================================================

def review_scope(
    state: RoadmapBuilderState,
) -> Command:
    manager_decision = interrupt(
        {
            "review_stage": "scope_review",
            "instruction": (
                "Review the generated professional roadmap paths."
            ),
            "scope_result": state[
                "scope_result"
            ],
            "allowed_actions": {
                "workflow": [
                    "approve",
                    "reject",
                ],
                "scope": [
                    "edit",
                    "regenerate",
                ],
            },
        }
    )

    decision = require_manager_decision(
        manager_decision
    )

    action = decision[
        "action"
    ]

    if action == "reject":
        return Command(
            update={
                "status": "rejected",
                "review_stage": None,
                "rejection_feedback": (
                    decision[
                        "feedback"
                    ]
                ),
            },
            goto="rejected",
        )

    if action == "approve":
        approved_scope = deepcopy(
            state[
                "scope_result"
            ]
        )

        return Command(
            update={
                "approved_scope_result": (
                    approved_scope
                ),
                "roadmap_packages": [],
                "approved_roadmap_packages": [],
                "current_roadmap_review_index": 0,
                "roadmap_regeneration_feedback": None,
                "status": "generating_roadmaps",
                "review_stage": None,
                "error": None,
            },
            goto="generate_roadmaps",
        )

    if action == "regenerate":
        if decision["stage"] != "scope":
            raise ValueError(
                "Scope review regeneration must use stage='scope'."
            )

        return Command(
            update={
                "scope_regeneration_feedback": (
                    decision[
                        "feedback"
                    ]
                ),
                "status": "generating_scope",
                "review_stage": None,
                "error": None,
            },
            goto="generate_scope",
        )

    if decision["stage"] != "scope":
        raise ValueError(
            "Scope review edit must use stage='scope'."
        )

    edited_scope = (
        validate_scope_result(
            decision[
                "data"
            ]
        )
    )

    return Command(
        update={
            "scope_result": (
                edited_scope
            ),
            "status": "awaiting_review",
            "review_stage": "scope_review",
            "error": None,
        },
        goto="review_scope",
    )


# =====================================================================
# GENERATE ONE ROADMAP PACKAGE
# =====================================================================

def generate_roadmaps(
    state: RoadmapBuilderState,
) -> Command:
    try:
        approved_scope = state[
            "approved_scope_result"
        ]

        paths = approved_scope[
            "paths"
        ]

        if not paths:
            raise ValueError(
                "Approved scope contains no paths."
            )

        roadmap_packages = deepcopy(
            state.get(
                "roadmap_packages",
                [],
            )
        )

        approved_packages = deepcopy(
            state.get(
                "approved_roadmap_packages",
                [],
            )
        )

        if (
            len(roadmap_packages)
            > len(paths)
        ):
            raise ValueError(
                "Generated roadmap package count exceeds approved scope path count."
            )

        if (
            len(approved_packages)
            > len(roadmap_packages)
        ):
            raise ValueError(
                "Approved roadmap package count exceeds generated roadmap package count."
            )

        for index, package in enumerate(
            roadmap_packages
        ):
            expected_path = paths[
                index
            ]

            if (
                package["path_id"]
                != expected_path["path_id"]
            ):
                raise ValueError(
                    "Generated roadmap packages do not match approved scope order."
                )

            if (
                package[
                    "path_title"
                ].casefold()
                != expected_path[
                    "title"
                ].casefold()
            ):
                raise ValueError(
                    "Generated roadmap package title does not match approved scope."
                )

        generation_index = len(
            roadmap_packages
        )

        if generation_index >= len(paths):
            raise ValueError(
                "There is no remaining roadmap path to generate."
            )

        if (
            generation_index
            != len(approved_packages)
        ):
            raise ValueError(
                "A new roadmap cannot be generated until the previous generated roadmap has been approved."
            )

        path = paths[
            generation_index
        ]

        package = (
            generate_roadmap_package(
                path[
                    "title"
                ]
            )
        )

        path_package = {
            "path_id": (
                path[
                    "path_id"
                ]
            ),
            "path_title": (
                path[
                    "title"
                ]
            ),
            "roadmap_result": (
                package[
                    "roadmap_result"
                ]
            ),
            "child_roadmaps": (
                package.get(
                    "child_roadmaps",
                    [],
                )
            ),
        }

        validated_package = (
            GeneratedPathRoadmap
            .model_validate(
                path_package
            )
            .model_dump()
        )

        roadmap_packages.append(
            validated_package
        )

        generation_attempts = (
            state.get(
                "roadmap_generation_attempts",
                0,
            )
        )

        if generation_index == 0:
            generation_attempts += 1

        return Command(
            update={
                "roadmap_packages": (
                    roadmap_packages
                ),
                "current_roadmap_review_index": (
                    generation_index
                ),
                "roadmap_generation_attempts": (
                    generation_attempts
                ),
                "roadmap_regeneration_feedback": None,
                "status": "awaiting_review",
                "review_stage": "roadmap_review",
                "error": None,
            },
            goto="review_current_roadmap",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Roadmap generation failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# REGENERATE CURRENT ROADMAP PACKAGE
# =====================================================================

def regenerate_current_roadmap(
    state: RoadmapBuilderState,
) -> Command:
    try:
        current_index = state[
            "current_roadmap_review_index"
        ]

        current_package = (
            get_current_roadmap_package(
                state
            )
        )

        feedback = state.get(
            "roadmap_regeneration_feedback"
        )

        if (
            not isinstance(
                feedback,
                str,
            )
            or not feedback.strip()
        ):
            raise ValueError(
                "Roadmap regeneration feedback is missing."
            )

        regenerated = (
            regenerate_roadmap_package(
                existing_package={
                    "roadmap_result": (
                        current_package[
                            "roadmap_result"
                        ]
                    ),
                    "child_roadmaps": (
                        current_package.get(
                            "child_roadmaps",
                            [],
                        )
                    ),
                },
                regeneration_feedback=(
                    feedback
                ),
            )
        )

        regenerated_package = {
            "path_id": (
                current_package[
                    "path_id"
                ]
            ),
            "path_title": (
                current_package[
                    "path_title"
                ]
            ),
            "roadmap_result": (
                regenerated[
                    "roadmap_result"
                ]
            ),
            "child_roadmaps": (
                regenerated.get(
                    "child_roadmaps",
                    [],
                )
            ),
        }

        validated_package = (
            GeneratedPathRoadmap
            .model_validate(
                regenerated_package
            )
            .model_dump()
        )

        roadmap_packages = deepcopy(
            state[
                "roadmap_packages"
            ]
        )

        roadmap_packages[
            current_index
        ] = validated_package

        attempts = (
            state.get(
                "roadmap_generation_attempts",
                0,
            )
            + 1
        )

        return Command(
            update={
                "roadmap_packages": (
                    roadmap_packages
                ),
                "roadmap_generation_attempts": (
                    attempts
                ),
                "roadmap_regeneration_feedback": None,
                "status": "awaiting_review",
                "review_stage": "roadmap_review",
                "error": None,
            },
            goto="review_current_roadmap",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Roadmap regeneration failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# CURRENT ROADMAP REVIEW
# =====================================================================

def review_current_roadmap(
    state: RoadmapBuilderState,
) -> Command:
    current_index = state[
        "current_roadmap_review_index"
    ]

    current_package = (
        get_current_roadmap_package(
            state
        )
    )

    approved_scope = state[
        "approved_scope_result"
    ]

    total_roadmaps = len(
        approved_scope[
            "paths"
        ]
    )

    manager_decision = interrupt(
        {
            "review_stage": "roadmap_review",
            "instruction": (
                "Review the current roadmap package and its direct child roadmaps."
            ),
            "current_roadmap_index": (
                current_index
            ),
            "current_roadmap_number": (
                current_index + 1
            ),
            "total_roadmaps": (
                total_roadmaps
            ),
            "roadmap_package": (
                current_package
            ),
            "allowed_actions": {
                "workflow": [
                    "approve",
                    "reject",
                ],
                "roadmap": [
                    "edit",
                    "regenerate",
                ],
            },
        }
    )

    decision = require_manager_decision(
        manager_decision
    )

    action = decision[
        "action"
    ]

    if action == "reject":
        return Command(
            update={
                "status": "rejected",
                "review_stage": None,
                "rejection_feedback": (
                    decision[
                        "feedback"
                    ]
                ),
            },
            goto="rejected",
        )

    if action == "regenerate":
        if decision["stage"] != "roadmap":
            raise ValueError(
                "Roadmap regeneration must use stage='roadmap'."
            )

        return Command(
            update={
                "roadmap_regeneration_feedback": (
                    decision[
                        "feedback"
                    ]
                ),
                "status": "generating_roadmaps",
                "review_stage": None,
                "error": None,
            },
            goto="regenerate_current_roadmap",
        )

    if action == "edit":
        if decision["stage"] != "roadmap":
            raise ValueError(
                "Roadmap edit must use stage='roadmap'."
            )

        edited_package = (
            validate_current_roadmap_package(
                data=decision[
                    "data"
                ],
                expected_package=(
                    current_package
                ),
            )
        )

        roadmap_packages = deepcopy(
            state[
                "roadmap_packages"
            ]
        )

        roadmap_packages[
            current_index
        ] = edited_package

        return Command(
            update={
                "roadmap_packages": (
                    roadmap_packages
                ),
                "status": "awaiting_review",
                "review_stage": "roadmap_review",
                "error": None,
            },
            goto="review_current_roadmap",
        )

    approved_packages = deepcopy(
        state.get(
            "approved_roadmap_packages",
            [],
        )
    )

    if any(
        package["path_id"]
        == current_package["path_id"]
        for package in approved_packages
    ):
        raise ValueError(
            "Current roadmap package has already been approved."
        )

    approved_packages.append(
        deepcopy(
            current_package
        )
    )

    next_index = (
        current_index + 1
    )

    if next_index >= total_roadmaps:
        return Command(
            update={
                "approved_roadmap_packages": (
                    approved_packages
                ),
                "current_roadmap_review_index": (
                    next_index
                ),
                "status": "ready_for_commit",
                "review_stage": None,
                "error": None,
            },
            goto="complete",
        )

    roadmap_packages = state.get(
        "roadmap_packages",
        [],
    )

    # Compatibility with workflows created before this redesign.
    # If the next package already exists, review it immediately.
    if next_index < len(
        roadmap_packages
    ):
        return Command(
            update={
                "approved_roadmap_packages": (
                    approved_packages
                ),
                "current_roadmap_review_index": (
                    next_index
                ),
                "status": "awaiting_review",
                "review_stage": "roadmap_review",
                "error": None,
            },
            goto="review_current_roadmap",
        )

    # New design:
    # previous package approved -> generate only the next package.
    return Command(
        update={
            "approved_roadmap_packages": (
                approved_packages
            ),
            "current_roadmap_review_index": (
                next_index
            ),
            "status": "generating_roadmaps",
            "review_stage": None,
            "error": None,
        },
        goto="generate_roadmaps",
    )


# =====================================================================
# TERMINAL NODES
# =====================================================================

def complete_workflow(
    state: RoadmapBuilderState,
) -> dict[str, Any]:
    return {
        "status": "ready_for_commit",
        "review_stage": None,
        "error": None,
    }


def reject_workflow(
    state: RoadmapBuilderState,
) -> dict[str, Any]:
    return {
        "status": "rejected",
        "review_stage": None,
    }


def fail_workflow(
    state: RoadmapBuilderState,
) -> dict[str, Any]:
    return {
        "status": "failed",
        "review_stage": None,
    }


# =====================================================================
# BUILD WORKFLOW
# =====================================================================

def build_roadmap_builder_workflow(
    checkpointer: Any,
):
    builder = StateGraph(
        RoadmapBuilderState
    )

    builder.add_node(
        "generate_scope",
        generate_scope,
    )

    builder.add_node(
        "review_scope",
        review_scope,
    )

    builder.add_node(
        "generate_roadmaps",
        generate_roadmaps,
    )

    builder.add_node(
        "regenerate_current_roadmap",
        regenerate_current_roadmap,
    )

    builder.add_node(
        "review_current_roadmap",
        review_current_roadmap,
    )

    builder.add_node(
        "complete",
        complete_workflow,
    )

    builder.add_node(
        "rejected",
        reject_workflow,
    )

    builder.add_node(
        "failed",
        fail_workflow,
    )

    builder.add_edge(
        START,
        "generate_scope",
    )

    builder.add_edge(
        "complete",
        END,
    )

    builder.add_edge(
        "rejected",
        END,
    )

    builder.add_edge(
        "failed",
        END,
    )

    return builder.compile(
        checkpointer=checkpointer
    )