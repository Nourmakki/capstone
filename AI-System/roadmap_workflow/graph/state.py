from typing import Literal, Optional, TypedDict

# =====================================================================
# ROADMAP CHOICE STATE
# =====================================================================
class RoadmapChoiceState(TypedDict):
    choice_id: int
    title: str
    description: str
    recommended: bool
    recommendation_reason: Optional[str]

# =====================================================================
# BASE ROADMAP NODE STATE
# =====================================================================

class BaseRoadmapNodeState(TypedDict):
    node_id: int
    title: str
    description: str
    order: int
    prerequisite_node_ids: list[int]
    learning_outcomes: list[str]

# =====================================================================
# STANDARD ROADMAP NODE STATE
# =====================================================================

class StandardRoadmapNodeState(
    BaseRoadmapNodeState
):
    type: Literal["standard"]

# =====================================================================
# CHOICE ROADMAP NODE STATE
# =====================================================================

class ChoiceRoadmapNodeState(
    BaseRoadmapNodeState
):
    type: Literal["choice"]
    choices: list[ RoadmapChoiceState]

# =====================================================================
# ROADMAP NODE STATE
# =====================================================================
RoadmapNodeState = (
    StandardRoadmapNodeState
    | ChoiceRoadmapNodeState
)
# =====================================================================
# ROADMAP RESULT STATE
# =====================================================================

class RoadmapResultState(TypedDict):
    roadmap_topic: str
    title: str
    description: str
    nodes: list[RoadmapNodeState]
    freshness_summary: str


# =====================================================================
# ROADMAP RESEARCH STATE
# =====================================================================

class RoadmapResearchState(TypedDict):
    summary: str
    sources: list[str]

# =====================================================================
# WORKFLOW STATUS
# =====================================================================
RoadmapBuilderWorkflowStatus = Literal[
    "starting",
    "researching_current_domain",
    "running_roadmap_builder_agent",
    "validating_roadmap",
    "ready",
    "failed",
]
# =====================================================================
# ROADMAP BUILDER WORKFLOW STATE
# =====================================================================
class RoadmapBuilderState(
    TypedDict,
    total=False,
):
    workflow_id: str
    roadmap_topic: str
    current_domain_research: (RoadmapResearchState)
    roadmap_result: (RoadmapResultState)
    validation_errors: list[str]
    generation_attempts: int
    status: RoadmapBuilderWorkflowStatus
    error: Optional[str]