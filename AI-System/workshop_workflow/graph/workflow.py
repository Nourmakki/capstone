from copy import deepcopy
from datetime import date
from typing import Any
from urllib.parse import urlparse

from langgraph.graph import (
    END,
    START,
    StateGraph,
)
from langgraph.types import (
    Command,
    interrupt,
)

from workshop_workflow.agents.workshop_agent import (
    run_workshop_agent,
)
from workshop_workflow.graph.state import (
    Workshop,
    WorkshopAgentState,
)


# =====================================================================
# MANAGER DECISION VALIDATION
# =====================================================================

def require_manager_decision(
    decision: Any,
) -> dict[str, Any]:
    """
    Validate the decision returned by the manager review interrupt.

    The Workshop Workflow intentionally supports only approve and reject.
    A fresh workflow can be started when a new search is required, so the
    workflow does not spend another Serper request on regeneration.
    """

    if not isinstance(
        decision,
        dict,
    ):
        raise ValueError(
            "Manager decision must be an object."
        )

    action = decision.get(
        "action"
    )

    if action not in {
        "approve",
        "reject",
    }:
        raise ValueError(
            "Manager action must be approve or reject."
        )

    # The shared API decision shape may include stage as null. Workshops
    # cannot be edited or regenerated inside this workflow.
    if decision.get("stage") is not None:
        raise ValueError(
            "Workshop approve/reject decisions must not contain a stage."
        )

    data = decision.get(
        "data"
    )

    feedback = decision.get(
        "feedback"
    )

    if action == "approve":
        if feedback is not None:
            raise ValueError(
                "Approve must not contain feedback."
            )

        if data is not None:
            if (
                not isinstance(
                    data,
                    dict,
                )
                or set(data.keys())
                != {
                    "approved_workshop_links"
                }
            ):
                raise ValueError(
                    "Selective approval data must contain exactly "
                    "'approved_workshop_links'."
                )

            approved_links = data[
                "approved_workshop_links"
            ]

            if (
                not isinstance(
                    approved_links,
                    list,
                )
                or not approved_links
            ):
                raise ValueError(
                    "approved_workshop_links must be a non-empty list. "
                    "Use reject when no workshop should be approved."
                )

            if len(approved_links) > 6:
                raise ValueError(
                    "At most 6 workshop links may be approved."
                )

            normalized_links = []

            for link in approved_links:
                if (
                    not isinstance(
                        link,
                        str,
                    )
                    or not link.strip()
                ):
                    raise ValueError(
                        "Every approved workshop link must be a "
                        "non-empty string."
                    )

                normalized_links.append(
                    link.strip()
                )

            if (
                len(set(normalized_links))
                != len(normalized_links)
            ):
                raise ValueError(
                    "approved_workshop_links must not contain duplicates."
                )

            data = {
                "approved_workshop_links": (
                    normalized_links
                ),
            }

    else:
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
                    "Reject feedback must be a non-empty string "
                    "when provided."
                )

            feedback = feedback.strip()

    return {
        "action": action,
        "data": data,
        "feedback": feedback,
    }


# =====================================================================
# INPUT VALIDATION
# =====================================================================

def validate_workshop_input(
    state: WorkshopAgentState,
) -> tuple[str, str | None]:
    roadmap_topic = state.get(
        "roadmap_topic"
    )

    if (
        not isinstance(
            roadmap_topic,
            str,
        )
        or not roadmap_topic.strip()
    ):
        raise ValueError(
            "roadmap_topic is required."
        )

    student_location = state.get(
        "student_location"
    )

    if student_location is not None:
        if not isinstance(
            student_location,
            str,
        ):
            raise ValueError(
                "student_location must be a string or null."
            )

        student_location = (
            student_location.strip()
            or None
        )

    return (
        roadmap_topic.strip(),
        student_location,
    )


def prepare_workshop_workflow(
    state: WorkshopAgentState,
) -> dict[str, Any]:
    try:
        (
            roadmap_topic,
            student_location,
        ) = validate_workshop_input(
            state
        )

    except Exception as error:
        return {
            "workshops": [],
            "approved_workshops": [],
            "status": "failed",
            "review_stage": None,
            "rejection_feedback": None,
            "error": str(error),
        }

    return {
        "roadmap_topic": roadmap_topic,
        "student_location": student_location,
        "workshops": [],
        "approved_workshops": [],
        "status": "running_workshop_agent",
        "review_stage": None,
        "rejection_feedback": None,
        "error": None,
    }


def route_after_preparation(
    state: WorkshopAgentState,
) -> str:
    if state.get("status") == "failed":
        return "failed"

    return "generate"


# =====================================================================
# WORKSHOP OUTPUT VALIDATION
# =====================================================================

def require_optional_text(
    value: Any,
    field_name: str,
) -> str | None:
    if value is None:
        return None

    if (
        not isinstance(
            value,
            str,
        )
        or not value.strip()
    ):
        raise ValueError(
            f"Workshop {field_name} must be a non-empty string or null."
        )

    return value.strip()


def require_text(
    value: Any,
    field_name: str,
) -> str:
    if (
        not isinstance(
            value,
            str,
        )
        or not value.strip()
    ):
        raise ValueError(
            f"Workshop {field_name} must be a non-empty string."
        )

    return value.strip()


def validate_workshop(
    workshop: Any,
) -> Workshop:
    if not isinstance(
        workshop,
        dict,
    ):
        raise ValueError(
            "Each workshop must be an object."
        )

    expected_fields = {
        "title",
        "organizer",
        "speaker",
        "date",
        "time",
        "location",
        "cost_type",
        "price",
        "link",
    }

    if set(workshop.keys()) != expected_fields:
        raise ValueError(
            "Each workshop must contain exactly the required workshop fields."
        )

    workshop_date = require_text(
        workshop.get("date"),
        "date",
    )

    try:
        parsed_date = date.fromisoformat(
            workshop_date
        )

    except ValueError as error:
        raise ValueError(
            "Workshop date must use YYYY-MM-DD format."
        ) from error

    if parsed_date < date.today():
        raise ValueError(
            "Workshop date must not be in the past."
        )

    cost_type = workshop.get(
        "cost_type"
    )

    if cost_type not in {
        "free",
        "paid",
        "not_specified",
    }:
        raise ValueError(
            "Workshop cost_type must be free, paid, or not_specified."
        )

    price = require_optional_text(
        workshop.get("price"),
        "price",
    )

    if cost_type == "paid" and price is None:
        raise ValueError(
            "A paid workshop must contain a price."
        )

    if cost_type == "not_specified" and price is not None:
        raise ValueError(
            "A workshop with unspecified cost must have a null price."
        )

    link = require_text(
        workshop.get("link"),
        "link",
    )

    parsed_link = urlparse(
        link
    )

    if (
        parsed_link.scheme not in {
            "http",
            "https",
        }
        or not parsed_link.netloc
    ):
        raise ValueError(
            "Workshop link must be a valid HTTP or HTTPS URL."
        )

    return {
        "title": require_text(
            workshop.get("title"),
            "title",
        ),
        "organizer": require_optional_text(
            workshop.get("organizer"),
            "organizer",
        ),
        "speaker": require_optional_text(
            workshop.get("speaker"),
            "speaker",
        ),
        "date": workshop_date,
        "time": require_optional_text(
            workshop.get("time"),
            "time",
        ),
        "location": require_text(
            workshop.get("location"),
            "location",
        ),
        "cost_type": cost_type,
        "price": price,
        "link": link,
    }


def validate_workshops(
    value: Any,
) -> list[Workshop]:
    if not isinstance(
        value,
        list,
    ):
        raise ValueError(
            "Workshop Agent must return a workshops list."
        )

    if len(value) > 6:
        raise ValueError(
            "Workshop Agent must return at most 6 workshops."
        )

    workshops = [
        validate_workshop(
            workshop
        )
        for workshop in value
    ]

    links = [
        workshop["link"]
        for workshop in workshops
    ]

    if len(set(links)) != len(links):
        raise ValueError(
            "Workshop links must be unique."
        )

    return workshops


def select_approved_workshops(
    workshops: list[Workshop],
    approval_data: dict[str, Any] | None,
) -> list[Workshop]:
    """
    Return all workshops for the original approve-all decision, or only the
    workshops whose links the manager selected.

    Selection is performed against the checkpointed proposal. A client
    cannot approve a new URL that was never returned by the Workshop Agent.
    """

    if approval_data is None:
        return deepcopy(
            workshops
        )

    selected_links = approval_data[
        "approved_workshop_links"
    ]

    proposed_links = {
        workshop["link"]
        for workshop in workshops
    }

    unknown_links = [
        link
        for link in selected_links
        if link not in proposed_links
    ]

    if unknown_links:
        raise ValueError(
            "Every approved workshop must belong to the original proposal. "
            f"Unknown links: {unknown_links}"
        )

    selected_link_set = set(
        selected_links
    )

    # Preserve the agent's original ordering rather than trusting the order
    # supplied by the client.
    return deepcopy([
        workshop
        for workshop in workshops
        if workshop["link"]
        in selected_link_set
    ])


# =====================================================================
# WORKSHOP AGENT
# =====================================================================

def generate_workshops(
    state: WorkshopAgentState,
) -> dict[str, Any]:
    generation_attempts = (
        state.get(
            "generation_attempts",
            0,
        )
        + 1
    )

    try:
        result = run_workshop_agent(
            roadmap_topic=(
                state["roadmap_topic"]
            ),
            student_location=state.get(
                "student_location"
            ),
        )

        if not isinstance(
            result,
            dict,
        ):
            raise ValueError(
                "Workshop Agent must return an object."
            )

        agent_error = result.get(
            "error"
        )

        if agent_error:
            raise RuntimeError(
                str(agent_error)
            )

        workshops = validate_workshops(
            result.get("workshops")
        )

    except Exception as error:
        return {
            "workshops": [],
            "approved_workshops": [],
            "generation_attempts": (
                generation_attempts
            ),
            "status": "failed",
            "review_stage": None,
            "error": (
                "Workshop Agent failed: "
                f"{error}"
            ),
        }

    if not workshops:
        # Nothing was proposed, so there is nothing for the manager to
        # review. This is a successful no-op rather than an agent failure.
        return {
            "workshops": [],
            "approved_workshops": [],
            "generation_attempts": (
                generation_attempts
            ),
            "status": "ready_for_commit",
            "review_stage": None,
            "rejection_feedback": None,
            "error": None,
        }

    return {
        "workshops": workshops,
        "approved_workshops": [],
        "generation_attempts": (
            generation_attempts
        ),
        "status": "awaiting_review",
        "review_stage": "workshops",
        "rejection_feedback": None,
        "error": None,
    }


def route_after_generation(
    state: WorkshopAgentState,
) -> str:
    status = state.get(
        "status"
    )

    if status == "failed":
        return "failed"

    if status == "ready_for_commit":
        return "complete"

    if status == "awaiting_review":
        return "review"

    raise ValueError(
        "Workshop Workflow reached an invalid status after generation."
    )


# =====================================================================
# MANAGER REVIEW
# =====================================================================

def review_workshops(
    state: WorkshopAgentState,
) -> Command:
    workshops = state.get(
        "workshops",
        [],
    )

    if not workshops:
        raise ValueError(
            "Workshop review requires at least one proposed workshop."
        )

    decision = interrupt({
        "type": "manager_review",
        "stage": "workshops",
        "message": (
            "Review the proposed workshops. "
            "Select one or more workshops to approve, approve all, "
            "or reject the workflow."
        ),
        "data": {
            "roadmap_topic": (
                state["roadmap_topic"]
            ),
            "student_location": state.get(
                "student_location"
            ),
            "workshops": workshops,
        },
        "allowed_actions": [
            "approve",
            "reject",
        ],
        "approval_selection": {
            "field": (
                "approved_workshop_links"
            ),
            "value_field": "link",
            "minimum_selected": 1,
            "maximum_selected": len(
                workshops
            ),
        },
    })

    decision = require_manager_decision(
        decision
    )

    if decision["action"] == "approve":
        return Command(
            update={
                "approved_workshops": (
                    select_approved_workshops(
                        workshops=workshops,
                        approval_data=decision.get(
                            "data"
                        ),
                    )
                ),
                "status": "ready_for_commit",
                "review_stage": None,
                "rejection_feedback": None,
                "error": None,
            },
            goto="complete",
        )

    return Command(
        update={
            "approved_workshops": [],
            "status": "rejected",
            "review_stage": None,
            "rejection_feedback": decision.get(
                "feedback"
            ),
            "error": None,
        },
        goto="rejected",
    )


# =====================================================================
# TERMINAL NODES
# =====================================================================

def complete_workflow(
    state: WorkshopAgentState,
) -> dict[str, Any]:
    return {
        "status": "ready_for_commit",
        "review_stage": None,
        "rejection_feedback": None,
        "error": None,
    }


def reject_workflow(
    state: WorkshopAgentState,
) -> dict[str, Any]:
    return {
        "status": "rejected",
        "review_stage": None,
        "error": None,
    }


def fail_workflow(
    state: WorkshopAgentState,
) -> dict[str, Any]:
    return {
        "status": "failed",
        "review_stage": None,
    }


# =====================================================================
# BUILD WORKFLOW
# =====================================================================

def build_workshop_workflow(
    checkpointer: Any,
):
    builder = StateGraph(
        WorkshopAgentState
    )

    builder.add_node(
        "prepare_workshop_workflow",
        prepare_workshop_workflow,
    )
    builder.add_node(
        "generate_workshops",
        generate_workshops,
    )
    builder.add_node(
        "review_workshops",
        review_workshops,
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
        "prepare_workshop_workflow",
    )

    builder.add_conditional_edges(
        "prepare_workshop_workflow",
        route_after_preparation,
        {
            "generate": "generate_workshops",
            "failed": "failed",
        },
    )

    builder.add_conditional_edges(
        "generate_workshops",
        route_after_generation,
        {
            "review": "review_workshops",
            "complete": "complete",
            "failed": "failed",
        },
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
