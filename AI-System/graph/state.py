from typing import TypedDict, Any, Optional, Dict, List
class RoadmapNode(TypedDict):
    id: int
    title: str
    description: Optional[str]

# class RoadmapEdge(TypedDict):
#     from_node_id: int
#     to_node_id: int

class TechTrend(TypedDict):
    title: str
    description: str
    reason: str
    confidence_score: float

# class NodeSuggestion(TypedDict):
#     title: str
#     description: str
#     difficulty: str
#     based_on_trend: str
#     reason: str

# class PlacementSuggestion(TypedDict):
#     suggested_node_title: str

#     # Existing nodes that should come before the new node
#     prerequisite_node_ids: list[int]

#     # Existing nodes that should come after the new node
#     successor_node_ids: list[int]

#     explanation: str


class AgentState(TypedDict, total=False):
    # Input data from Laravel later
    roadmap_id: int
    roadmap_title: str
    roadmap_topic: str
    version_id: int
    version_number: str

    nodes: list[RoadmapNode]
    # edges: list[RoadmapEdge]

    # Trend Agent output
    trends: list[TechTrend]

    # Node Suggestion Agent output
    # node_suggestions: list[NodeSuggestion]

    # # Placement Agent output
    # placement_suggestions: list[PlacementSuggestion]

    # # Later for human-in-the-loop
    # admin_decision: Optional[dict[str, Any]]

    # General workflow tracking
    status: str
    error: Optional[str]