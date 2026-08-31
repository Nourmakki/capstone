from typing import Any

from api.roadmap_builder.schema import (
    RoadmapBuilderWorkflowResponse,
)


def get_current_package(
    result: dict[str, Any],
) -> dict[str, Any] | None:
    packages = result.get(
        "roadmap_packages",
        [],
    )

    if not packages:
        return None

    current_index = result.get(
        "current_roadmap_review_index",
        0,
    )

    if (
        current_index < 0
        or current_index >= len(packages)
    ):
        return None

    return packages[
        current_index
    ]


def build_roadmap_builder_workflow_response(
    thread_id: str,
    result: dict[str, Any],
) -> RoadmapBuilderWorkflowResponse:
    status = result.get(
        "status"
    )

    review_stage = result.get(
        "review_stage"
    )

    response_data = None

    if (
        status == "awaiting_review"
        and review_stage == "scope_review"
    ):
        scope_result = result.get(
            "scope_result"
        )

        if scope_result is None:
            raise ValueError(
                "Scope result is missing from a scope-review workflow."
            )

        response_data = {
            "scope_result": (
                scope_result
            ),
        }

    elif (
        status == "awaiting_review"
        and review_stage == "roadmap_review"
    ):
        approved_scope = result.get(
            "approved_scope_result"
        )

        current_package = (
            get_current_package(
                result
            )
        )

        current_index = result.get(
            "current_roadmap_review_index",
            0,
        )

        if approved_scope is None:
            raise ValueError(
                "Approved scope is missing from roadmap review."
            )

        if current_package is None:
            raise ValueError(
                "Current roadmap package is missing from roadmap review."
            )

        total_roadmaps = len(
            approved_scope[
                "paths"
            ]
        )

        response_data = {
            "scope_result": (
                approved_scope
            ),
            "roadmap_package": (
                current_package
            ),
            "current_roadmap_number": (
                current_index + 1
            ),
            "total_roadmaps": (
                total_roadmaps
            ),
        }

    elif status == "ready_for_commit":
        approved_scope = result.get(
            "approved_scope_result"
        )

        approved_packages = result.get(
            "approved_roadmap_packages"
        )

        if approved_scope is None:
            raise ValueError(
                "Approved scope is missing from a ready-for-commit workflow."
            )

        if approved_packages is None:
            raise ValueError(
                "Approved roadmap packages are missing from a ready-for-commit workflow."
            )

        expected_count = len(
            approved_scope[
                "paths"
            ]
        )

        if (
            len(approved_packages)
            != expected_count
        ):
            raise ValueError(
                "Ready-for-commit workflow does not contain one approved roadmap package for every approved scope path."
            )

        response_data = {
            "scope_result": (
                approved_scope
            ),
            "approved_roadmap_packages": (
                approved_packages
            ),
        }

    elif status == "rejected":
        scope_result = (
            result.get(
                "approved_scope_result"
            )
            or result.get(
                "scope_result"
            )
        )

        current_package = (
            get_current_package(
                result
            )
        )

        if current_package is not None:
            current_index = result.get(
                "current_roadmap_review_index",
                0,
            )

            total_roadmaps = (
                len(
                    scope_result[
                        "paths"
                    ]
                )
                if scope_result
                else None
            )

            response_data = {
                "scope_result": (
                    scope_result
                ),
                "roadmap_package": (
                    current_package
                ),
                "current_roadmap_number": (
                    current_index + 1
                ),
                "total_roadmaps": (
                    total_roadmaps
                ),
            }

        elif scope_result is not None:
            response_data = {
                "scope_result": (
                    scope_result
                ),
            }

    return RoadmapBuilderWorkflowResponse(
        thread_id=thread_id,
        status=status,
        review_stage=review_stage,
        data=response_data,
        rejection_feedback=result.get(
            "rejection_feedback"
        ),
        scope_generation_attempts=result.get(
            "scope_generation_attempts",
            0,
        ),
        roadmap_generation_attempts=result.get(
            "roadmap_generation_attempts",
            0,
        ),
        error=result.get(
            "error"
        ),
    )