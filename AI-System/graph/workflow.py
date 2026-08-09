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
            "Invalid admin decision action."
        )

    if action in {
        "edit",
        "regenerate",
    }:
        stage = decision.get("stage")

        if stage not in {
            "trends",
            "nodes",
            "placement",
        }:
            raise ValueError(
                "Edit and regenerate decisions must "
                "contain stage='trends', 'nodes', "
                "or 'placement'."
            )

    if action == "edit":
        data = decision.get("data")

        if not isinstance(data, dict):
            raise ValueError(
                "Edit decisions must contain a data object."
            )

        regenerate_after_edit = decision.get(
            "regenerate_after_edit",
            False,
        )

        if not isinstance(
            regenerate_after_edit,
            bool,
        ):
            raise ValueError(
                "regenerate_after_edit must be boolean."
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

def detect_tech_trends(state: AgentState,) -> Command:
    try:
        result = run_tech_trends_agent(
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
                "node_suggestions": [],
                "placement_suggestions": [],
                "existing_edges_to_remove": [],
                "status": "running_node_agent",
                "review_stage": None,
                "error": None,
            },
            goto="suggest_nodes",
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
# NODE SUGGESTION AGENT NODE
# =====================================================================

def suggest_nodes(state: AgentState,) -> Command:
    try:
        result = run_node_suggestion_agent(
            roadmap_topic=state["roadmap_topic"],
            approved_trends=state["trends"],
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
                "placement_suggestions": [],
                "existing_edges_to_remove": [],
                "status": "running_placement_agent",
                "review_stage": None,
                "error": None,
            },
            goto="place_nodes",
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
# PLACEMENT AGENT NODE
# =====================================================================

def place_nodes(state: AgentState,) -> Command:
    try:
        result = run_placement_agent(
            roadmap_topic=state["roadmap_topic"],
            existing_nodes=state["nodes"],
            existing_edges=state["edges"],
            suggested_nodes=state[
                "node_suggestions"
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
                "status": "awaiting_review",
                "review_stage": "full_review",
                "error": None,
            },
            goto="review_all",
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

def review_all(state: AgentState,) -> Command:
    decision = interrupt(
        {
            "review_stage": "full_review",
            "instruction": (
                "Review the complete AI roadmap update proposal."
            ),
            "trends": state["trends"],
            "node_suggestions": state[
                "node_suggestions"
            ],
            "placement_suggestions": state[
                "placement_suggestions"
            ],
            "existing_edges_to_remove": state[
                "existing_edges_to_remove"
            ],
            "allowed_actions": {
                "workflow": [
                    "approve",
                    "reject",
                ],
                "trends": [
                    "edit",
                    "regenerate",
                ],
                "nodes": [
                    "edit",
                    "regenerate",
                ],
                "placement": [
                    "edit",
                    "regenerate",
                ],
            },
        }
    )

    decision = require_admin_decision(
        decision
    )

    action = decision["action"]

    # -------------------------------------------------
    # REJECT COMPLETE WORKFLOW
    # -------------------------------------------------

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

    # -------------------------------------------------
    # APPROVE EVERYTHING
    # -------------------------------------------------

    if action == "approve":
        return Command(
            update={
                "approved_trends": state[
                    "trends"
                ],
                "approved_node_suggestions": state[
                    "node_suggestions"
                ],
                "approved_placement_suggestions": state[
                    "placement_suggestions"
                ],
                "approved_existing_edges_to_remove": state[
                    "existing_edges_to_remove"
                ],
                "status": "ready_for_commit",
                "review_stage": None,
                "rejection_feedback": None,
            },
            goto="complete",
        )

    stage = decision["stage"]

    # -------------------------------------------------
    # REGENERATE
    # -------------------------------------------------

    if action == "regenerate":

        if stage == "trends":
            return Command(
                update={
                    "status": "running_trend_agent",
                    "review_stage": None,
                },
                goto="detect_tech_trends",
            )

        if stage == "nodes":
            return Command(
                update={
                    "status": "running_node_agent",
                    "review_stage": None,
                },
                goto="suggest_nodes",
            )

        return Command(
            update={
                "status": "running_placement_agent",
                "review_stage": None,
            },
            goto="place_nodes",
        )

    # -------------------------------------------------
    # EDIT TRENDS
    # -------------------------------------------------

    if stage == "trends":
        edited_trends = require_edited_list(
            decision,
            "trends",
        )

        regenerate_after_edit = decision.get(
            "regenerate_after_edit",
            False,
        )

        if regenerate_after_edit:
            return Command(
                update={
                    "trends": edited_trends,
                    "status": "running_node_agent",
                    "review_stage": None,
                },
                goto="suggest_nodes",
            )

        return Command(
            update={
                "trends": edited_trends,
                "status": "awaiting_review",
                "review_stage": "full_review",
            },
            goto="review_all",
        )

    # -------------------------------------------------
    # EDIT NODES
    # -------------------------------------------------

    if stage == "nodes":
        edited_nodes = require_edited_list(
            decision,
            "node_suggestions",
        )

        regenerate_after_edit = decision.get(
            "regenerate_after_edit",
            False,
        )

        if regenerate_after_edit:
            return Command(
                update={
                    "node_suggestions": edited_nodes,
                    "status": "running_placement_agent",
                    "review_stage": None,
                },
                goto="place_nodes",
            )

        return Command(
            update={
                "node_suggestions": edited_nodes,
                "status": "awaiting_review",
                "review_stage": "full_review",
            },
            goto="review_all",
        )

    # -------------------------------------------------
    # EDIT PLACEMENT
    # -------------------------------------------------

    edited_placements = require_edited_list(
        decision,
        "placement_suggestions",
    )

    edited_edges = require_edited_list(
        decision,
        "existing_edges_to_remove",
    )

    return Command(
        update={
            "placement_suggestions": (
                edited_placements
            ),
            "existing_edges_to_remove": (
                edited_edges
            ),
            "status": "awaiting_review",
            "review_stage": "full_review",
        },
        goto="review_all",
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
        "suggest_nodes",
        suggest_nodes,
    )


    builder.add_node(
        "place_nodes",
        place_nodes,
    )

    builder.add_node(
        "review_all",
        review_all,
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
