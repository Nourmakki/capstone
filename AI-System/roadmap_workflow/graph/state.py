from typing import Literal, Optional, TypedDict


class RoadmapScopePathState(TypedDict):
    path_id: int
    title: str
    description: str


class RoadmapScopeResultState(TypedDict):
    input_topic: str
    is_broad_topic: bool
    paths: list[RoadmapScopePathState]
    scope_summary: str


class RoadmapChoiceState(TypedDict):
    choice_id: int
    title: str
    description: str
    separate_roadmap: bool
    recommended: bool
    recommendation_reason: Optional[str]


class BaseRoadmapNodeState(TypedDict):
    node_id: int
    title: str
    description: str
    order: int
    prerequisite_node_ids: list[int]
    learning_outcomes: list[str]


class StandardRoadmapNodeState(BaseRoadmapNodeState):
    type: Literal["standard"]


class ChoiceRoadmapNodeState(BaseRoadmapNodeState):
    type: Literal["choice"]
    choices: list[RoadmapChoiceState]


RoadmapNodeState = (
    StandardRoadmapNodeState
    | ChoiceRoadmapNodeState
)


class RoadmapResultState(TypedDict):
    roadmap_topic: str
    title: str
    description: str
    nodes: list[RoadmapNodeState]
    freshness_summary: str


class GeneratedChildRoadmapState(TypedDict):
    parent_node_id: int
    choice_id: int
    choice_title: str
    roadmap_result: RoadmapResultState


class GeneratedPathRoadmapState(TypedDict):
    path_id: int
    path_title: str
    roadmap_result: RoadmapResultState
    child_roadmaps: list[
        GeneratedChildRoadmapState
    ]


WorkflowStatus = Literal[
    "starting",
    "generating_scope",
    "awaiting_review",
    "generating_roadmaps",
    "ready_for_commit",
    "rejected",
    "failed",
]


ReviewStage = Literal[
    "scope_review",
    "roadmap_review",
]


class RoadmapBuilderState(
    TypedDict,
    total=False,
):
    workflow_id: str

    # Original Laravel input.
    roadmap_topic: str

    # Current and accepted scope.
    scope_result: RoadmapScopeResultState
    approved_scope_result: (
        RoadmapScopeResultState
    )

    # All generated roadmap packages.
    roadmap_packages: list[
        GeneratedPathRoadmapState
    ]

    # Packages explicitly approved so far.
    approved_roadmap_packages: list[
        GeneratedPathRoadmapState
    ]

    # Which roadmap package the manager
    # is currently reviewing.
    current_roadmap_review_index: int

    # Generation tracking.
    scope_generation_attempts: int
    roadmap_generation_attempts: int

    # Regeneration feedback.
    scope_regeneration_feedback: (
        str
        | None
    )
    roadmap_regeneration_feedback: (
        str
        | None
    )

    # Workflow control.
    status: WorkflowStatus
    review_stage: ReviewStage | None
    rejection_feedback: str | None
    error: str | None