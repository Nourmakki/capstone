from typing import TypedDict, Any, Optional, Dict, Literal

class RoadmapNode(TypedDict):
    id: int
    title: str
    description: Optional[str]

class RoadmapEdge(TypedDict):
    from_node_id: int
    to_node_id: int

class TechTrend(TypedDict):
    title: str
    description: str
    confidence_score: float
    sources: list[Dict[str, Any]]

class NodeSuggestion(TypedDict):
    title: str
    description: str
    difficulty: str
    based_on_trend: str

class NodeSuggestionWithId(NodeSuggestion):
    id: int

class PlacementSuggestion(TypedDict):
    suggested_node_id: int
    suggested_node_title: str
    direct_prerequisite_node_ids: list[int]
    direct_successor_node_ids: list[int]
    recommended_predecessor_node_ids: list[int]
    explanation: str

class ExistingEdgeRemoval(TypedDict):
    from_node_id: int
    to_node_id: int



WorkflowStatus = Literal[
    "starting",
    "running_trend_agent",
    "running_node_agent",
    "running_placement_agent",
    "awaiting_review",
    "ready_for_commit",
    "rejected",
    "failed",
]

ReviewStage = Literal[
   "full_review",
]


class AgentState(TypedDict, total=False):
    # One unique execution of the complete workflow.
    workflow_id: str

    # Input received from Laravel.
    roadmap_id: int
    roadmap_topic: str
    version_id: int
    version_number: str

    nodes: list[RoadmapNode]
    edges: list[RoadmapEdge]

    # Generated Tech Trend Agent output.
    trends: list[TechTrend]

    # Admin-approved or admin-edited trends.
    approved_trends: list[TechTrend]

    # Generated Node Suggestion Agent output.
    node_suggestions: list[NodeSuggestion]

    # Admin-approved or admin-edited node suggestions.
    approved_node_suggestions: list[NodeSuggestion]

    # Suggested nodes prepared by the Placement Agent with temporary IDs.
    node_suggestions_with_ids: list[NodeSuggestionWithId]

    # Generated Placement Agent output.
    placement_suggestions: list[PlacementSuggestion]
    existing_edges_to_remove: list[ExistingEdgeRemoval]

    # Admin-approved or admin-edited placement output.
    approved_placement_suggestions: list[PlacementSuggestion]
    approved_existing_edges_to_remove: list[ExistingEdgeRemoval]

    # Workflow tracking.
    status: WorkflowStatus
    review_stage: ReviewStage | None
    rejection_feedback: str | None
    error: str | None