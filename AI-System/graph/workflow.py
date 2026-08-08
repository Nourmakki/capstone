from typing import Any
from langgraph.graph import END, START, StateGraph
from langgraph.types import Command, interrupt
from .state import AgentState
from agents.trend_agent import run_tech_trends_agent
from agents.node_suggestion_agent import run_node_suggestion_agent
from agents.placement_agent import run_placement_agent

# =====================================================================
# HELPERS
# =====================================================================

def require_admin_decision(decision: Any,) -> dict[str, Any]:
    """
    Validate the decision received from Laravel.

    Expected:
        {"action": "approve"}

        {"action": "edit", "data": {...}}

        {"action": "regenerate"}

        {"action": "reject", "feedback": "..."}
    """

    if not isinstance(decision, dict):
        raise ValueError(
            "The admin decision must be an object."
        )

    action = decision.get("action")

    if action not in {
        "approve",
        "edit",
        "regenerate",
        "reject",
    }:
        raise ValueError(
            "Invalid admin decision. "
            f"Received action={action!r}, "
            f"decision={decision!r}"
    )

    return decision


def require_edited_list(decision: dict[str, Any],field_name: str,) -> list[dict[str, Any]]:
    """
    Read one edited list from decision['data'].
    """

    data = decision.get("data")

    if not isinstance(data, dict):
        raise ValueError(
            "An edit decision must contain a data object."
        )

    edited_items = data.get(field_name)

    if not isinstance(edited_items, list):
        raise ValueError(
            f"Edited data must contain a '{field_name}' list."
        )

    return edited_items


# =====================================================================
# TECH TREND AGENT NODE
# =====================================================================

def detect_tech_trends(
    state: AgentState,
) -> Command:
    try:
        result = run_tech_trends_agent(
            roadmap_title=state["roadmap_title"],
            roadmap_topic=state["roadmap_topic"],
            existing_nodes=state["nodes"],
        )

        trends = result.get("trends")

        if not isinstance(trends, list):
            raise ValueError(
                "Tech Trend Agent must return a trends list."
            )

        return Command(
            update={
                "trends": trends,
                "status": "awaiting_trend_review",
                "review_stage": "trends",
                "error": None,
            },
            goto="review_trends",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Tech Trend Agent failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# TREND REVIEW NODE
# =====================================================================

def review_trends(
    state: AgentState,
) -> Command:
    """
    Pause until the admin approves, edits,regenerates or rejects the trends.

    Important:
    Do not perform database writes before interrupt().
    This node starts again from the beginning when resumed.
    """

    decision = interrupt(
        {
            "review_stage": "trends",
            "instruction": (
                "Review the detected technology trends."
            ),
            "trends": state["trends"],
            "allowed_actions": [
                "approve",
                "edit",
                "regenerate",
                "reject",
            ],
        }
    )

    decision = require_admin_decision(decision)
    action = decision["action"]

    if action == "regenerate":
        return Command(
            update={
                "status": "running_trend_agent",
                "review_stage": None,
                "error": None,
                "rejection_feedback": None,
            },
            goto="detect_tech_trends",
    )

    if action == "reject":
        return Command(
            update={
                "status": "rejected",
                "review_stage": None,
                "rejection_feedback": decision.get(
                    "feedback"
                ),
            },
            goto="rejected",
        )

    if action == "edit":
        approved_trends = require_edited_list(
            decision=decision,
            field_name="trends",
        )
    else:
        approved_trends = state["trends"]

    return Command(
        update={
            "approved_trends": approved_trends,
            "status": "running_node_agent",
            "review_stage": None,
            "rejection_feedback": None,
        },
        goto="suggest_nodes",
    )


# =====================================================================
# NODE SUGGESTION AGENT NODE
# =====================================================================

def suggest_nodes(state: AgentState,) -> Command:
    try:
        result = run_node_suggestion_agent(
            roadmap_topic=state["roadmap_topic"],
            approved_trends=state["approved_trends"],
        )

        suggestions = result.get(
            "node_suggestions"
        )

        if not isinstance(suggestions, list):
            raise ValueError(
                "Node Suggestion Agent must return a "
                "node_suggestions list."
            )

        return Command(
            update={
                "node_suggestions": suggestions,
                "status": "awaiting_node_review",
                "review_stage": "nodes",
                "error": None,
            },
            goto="review_nodes",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Node Suggestion Agent failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# NODE SUGGESTION REVIEW
# =====================================================================

def review_nodes(state: AgentState,) -> Command:
    decision = interrupt(
        {
            "review_stage": "nodes",
            "instruction": (
                "Review the generated roadmap node suggestions."
            ),
            "node_suggestions": state[
                "node_suggestions"
            ],
            "allowed_actions": [
                "approve",
                "edit",
                "regenerate",
                "reject",
            ],
        }
    )

    decision = require_admin_decision(decision)
    action = decision["action"]

    if action == "regenerate":
        return Command(
            update={
                "status": "running_node_agent",
                "review_stage": None,
                "error": None,
                "rejection_feedback": None,
            },
            goto="suggest_nodes",
        )

    if action == "reject":
        return Command(
            update={
                "status": "rejected",
                "review_stage": None,
                "rejection_feedback": decision.get(
                    "feedback"
                ),
            },
            goto="rejected",
        )

    if action == "edit":
        approved_suggestions = require_edited_list(
            decision=decision,
            field_name="node_suggestions",
        )
    else:
        approved_suggestions = state[
            "node_suggestions"
        ]

    return Command(
        update={
            "approved_node_suggestions": (
                approved_suggestions
            ),
            "status": "running_placement_agent",
            "review_stage": None,
            "rejection_feedback": None,
        },
        goto="place_nodes",
    )


# =====================================================================
# PLACEMENT AGENT NODE
# =====================================================================

def place_nodes(state: AgentState,) -> Command:
    try:
        result = run_placement_agent(
            roadmap_topic=state["roadmap_topic"],
            existing_nodes=state["nodes"],
            existing_edges=state["edges"],
            suggested_nodes=state[
                "approved_node_suggestions"
            ],
        )

        placements = result.get(
            "placement_suggestions"
        )

        edges_to_remove = result.get(
            "existing_edges_to_remove"
        )

        if not isinstance(placements, list):
            raise ValueError(
                "Placement Agent must return a "
                "placement_suggestions list."
            )

        if not isinstance(edges_to_remove, list):
            raise ValueError(
                "Placement Agent must return an "
                "existing_edges_to_remove list."
            )

        return Command(
            update={
                "placement_suggestions": placements,
                "existing_edges_to_remove": (
                    edges_to_remove
                ),
                "status": "awaiting_placement_review",
                "review_stage": "placement",
                "error": None,
            },
            goto="review_placement",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Placement Agent failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# PLACEMENT REVIEW
# =====================================================================

def review_placement(state: AgentState,) -> Command:
    decision = interrupt(
        {
            "review_stage": "placement",
            "instruction": (
                "Review the final node placements and "
                "existing edges proposed for removal."
            ),
            "placement_suggestions": state[
                "placement_suggestions"
            ],
            "existing_edges_to_remove": state[
                "existing_edges_to_remove"
            ],
            "allowed_actions": [
                "approve",
                "edit",
                "regenerate",
                "reject",
            ],
        }
    )

    decision = require_admin_decision(decision)
    action = decision["action"]

    if action == "regenerate":
        return Command(
            update={
                "status": "running_placement_agent",
                "review_stage": None,
                "error": None,
                "rejection_feedback": None,
            },
            goto="place_nodes",
        )

    if action == "reject":
        return Command(
            update={
                "status": "rejected",
                "review_stage": None,
                "rejection_feedback": decision.get(
                    "feedback"
                ),
            },
            goto="rejected",
        )

    if action == "edit":
        approved_placements = require_edited_list(
            decision=decision,
            field_name="placement_suggestions",
        )

        approved_edges = require_edited_list(
            decision=decision,
            field_name="existing_edges_to_remove",
        )
    else:
        approved_placements = state[
            "placement_suggestions"
        ]

        approved_edges = state[
            "existing_edges_to_remove"
        ]

    return Command(
        update={
            "approved_placement_suggestions": (
                approved_placements
            ),
            "approved_existing_edges_to_remove": (
                approved_edges
            ),
            "status": "ready_for_commit",
            "review_stage": None,
            "rejection_feedback": None,
        },
        goto="complete",
    )


# =====================================================================
# TERMINAL NODES
# =====================================================================

def complete_workflow(
    state: AgentState,
) -> dict[str, Any]:
    """
    Laravel reads the approved fields and performs the real
    database transaction after this node.
    """

    return {
        "status": "ready_for_commit",
        "review_stage": None,
        "error": None,
    }


def reject_workflow(
    state: AgentState,
) -> dict[str, Any]:
    return {
        "status": "rejected",
        "review_stage": None,
    }


def fail_workflow(
    state: AgentState,
) -> dict[str, Any]:
    return {
        "status": "failed",
        "review_stage": None,
    }


# =====================================================================
# BUILD WORKFLOW
# =====================================================================

def build_roadmap_workflow(
    checkpointer: Any,
):
    builder = StateGraph(AgentState)

    builder.add_node(
        "detect_tech_trends",
        detect_tech_trends,
    )

    builder.add_node(
        "review_trends",
        review_trends,
    )

    builder.add_node(
        "suggest_nodes",
        suggest_nodes,
    )

    builder.add_node(
        "review_nodes",
        review_nodes,
    )

    builder.add_node(
        "place_nodes",
        place_nodes,
    )

    builder.add_node(
        "review_placement",
        review_placement,
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
        "detect_tech_trends",
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
