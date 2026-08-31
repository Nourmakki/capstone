from typing import Annotated, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    StringConstraints,
    model_validator,
)


# =====================================================================
# COMMON TYPES
# =====================================================================

NonEmptyString = Annotated[
    str,
    StringConstraints(
        strip_whitespace=True,
        min_length=1,
    ),
]

PositiveId = Annotated[
    int,
    Field(gt=0),
]


# =====================================================================
# ROADMAP SCOPE MODELS
# =====================================================================

class RoadmapScopePath(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    path_id: PositiveId
    title: NonEmptyString
    description: NonEmptyString


class RoadmapScopeResult(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    input_topic: NonEmptyString
    is_broad_topic: bool
    paths: list[RoadmapScopePath]
    scope_summary: NonEmptyString

    @model_validator(mode="after")
    def validate_scope(
        self,
    ) -> "RoadmapScopeResult":
        if not self.paths:
            raise ValueError(
                "Roadmap scope must contain at least one path."
            )

        path_ids = [
            path.path_id
            for path in self.paths
        ]

        expected_ids = list(
            range(
                1,
                len(self.paths) + 1,
            )
        )

        if path_ids != expected_ids:
            raise ValueError(
                "Scope path IDs must start at 1 and be consecutive."
            )

        normalized_titles = [
            path.title.casefold()
            for path in self.paths
        ]

        if len(normalized_titles) != len(
            set(normalized_titles)
        ):
            raise ValueError(
                "Scope path titles must be unique."
            )

        if (
            self.is_broad_topic
            and len(self.paths) < 2
        ):
            raise ValueError(
                "A broad topic must be divided into at least two professional paths."
            )

        if (
            not self.is_broad_topic
            and len(self.paths) != 1
        ):
            raise ValueError(
                "A roadmap-ready topic must contain exactly one path."
            )

        return self


# =====================================================================
# ROADMAP CHOICE
# =====================================================================

class RoadmapChoice(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    choice_id: PositiveId
    title: NonEmptyString
    description: NonEmptyString
    separate_roadmap: bool
    recommended: bool
    recommendation_reason: NonEmptyString | None = None

    @model_validator(mode="after")
    def validate_recommendation_reason(
        self,
    ) -> "RoadmapChoice":
        if (
            self.recommended
            and self.recommendation_reason is None
        ):
            raise ValueError(
                "A recommended roadmap choice must include a recommendation_reason."
            )

        return self


# =====================================================================
# BASE ROADMAP NODE
# =====================================================================

class BaseRoadmapNode(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: PositiveId
    title: NonEmptyString
    description: NonEmptyString
    order: PositiveId
    prerequisite_node_ids: list[PositiveId] = Field(
        default_factory=list
    )
    learning_outcomes: list[NonEmptyString] = Field(
        min_length=1
    )


# =====================================================================
# STANDARD ROADMAP NODE
# =====================================================================

class StandardRoadmapNode(
    BaseRoadmapNode
):
    type: Literal["standard"]


# =====================================================================
# CHOICE ROADMAP NODE
# =====================================================================

class ChoiceRoadmapNode(
    BaseRoadmapNode
):
    type: Literal["choice"]
    choices: list[RoadmapChoice] = Field(
        min_length=2
    )

    @model_validator(mode="after")
    def validate_choices(
        self,
    ) -> "ChoiceRoadmapNode":
        choice_ids = [
            choice.choice_id
            for choice in self.choices
        ]

        if len(choice_ids) != len(
            set(choice_ids)
        ):
            raise ValueError(
                "Roadmap choice IDs must be unique within a choice node."
            )

        normalized_titles = [
            choice.title.casefold()
            for choice in self.choices
        ]

        if len(normalized_titles) != len(
            set(normalized_titles)
        ):
            raise ValueError(
                "Roadmap choices must have unique titles within a choice node."
            )

        recommended_count = sum(
            1
            for choice in self.choices
            if choice.recommended
        )

        if recommended_count != 1:
            raise ValueError(
                "A choice roadmap node must contain exactly one recommended choice."
            )

        return self


# =====================================================================
# ROADMAP NODE UNION
# =====================================================================

RoadmapNode = Annotated[
    StandardRoadmapNode
    | ChoiceRoadmapNode,
    Field(discriminator="type"),
]


# =====================================================================
# ROADMAP AGENT RESULT
# =====================================================================

class RoadmapAgentResult(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    roadmap_topic: NonEmptyString
    title: NonEmptyString
    description: NonEmptyString
    nodes: list[RoadmapNode] = Field(
        min_length=2
    )
    freshness_summary: NonEmptyString

    @model_validator(mode="after")
    def validate_roadmap_structure(
        self,
    ) -> "RoadmapAgentResult":
        node_ids = [
            node.node_id
            for node in self.nodes
        ]

        expected_node_ids = list(
            range(
                1,
                len(self.nodes) + 1,
            )
        )

        if node_ids != expected_node_ids:
            raise ValueError(
                "Roadmap node IDs must start at 1 and be consecutive."
            )

        node_orders = [
            node.order
            for node in self.nodes
        ]

        expected_orders = list(
            range(
                1,
                len(self.nodes) + 1,
            )
        )

        if node_orders != expected_orders:
            raise ValueError(
                "Roadmap node order values must start at 1 and be consecutive."
            )

        node_by_id = {
            node.node_id: node
            for node in self.nodes
        }

        for node in self.nodes:
            prerequisite_ids = (
                node.prerequisite_node_ids
            )

            if len(prerequisite_ids) != len(
                set(prerequisite_ids)
            ):
                raise ValueError(
                    f"Roadmap node {node.node_id} contains duplicate prerequisites."
                )

            for prerequisite_id in prerequisite_ids:
                if prerequisite_id not in node_by_id:
                    raise ValueError(
                        f"Roadmap node {node.node_id} references a prerequisite node that does not exist: {prerequisite_id}."
                    )

                if prerequisite_id == node.node_id:
                    raise ValueError(
                        f"Roadmap node {node.node_id} cannot depend on itself."
                    )

                prerequisite_node = (
                    node_by_id[
                        prerequisite_id
                    ]
                )

                if (
                    prerequisite_node.order
                    >= node.order
                ):
                    raise ValueError(
                        f"Roadmap node {node.node_id} has a prerequisite that does not appear earlier in the roadmap."
                    )

        return self


# =====================================================================
# GENERATED CHILD ROADMAP
# =====================================================================

class GeneratedChildRoadmap( BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    parent_node_id: PositiveId
    choice_id: PositiveId
    choice_title: NonEmptyString
    roadmap_result: RoadmapAgentResult

    @model_validator(mode="after")
    def validate_child_roadmap(
        self,
    ) -> "GeneratedChildRoadmap":
        if (
            self.roadmap_result.roadmap_topic.casefold()
            != self.choice_title.casefold()
        ):
            raise ValueError(
                "Child roadmap topic must match its parent choice title."
            )

        for node in self.roadmap_result.nodes:
            if node.type != "choice":
                continue

            for choice in node.choices:
                if choice.separate_roadmap:
                    raise ValueError(
                        "A child roadmap cannot contain choices with separate_roadmap=true."
                    )

        return self


# =====================================================================
# GENERATED PATH ROADMAP
# =====================================================================

class GeneratedPathRoadmap(
    BaseModel
):
    model_config = ConfigDict(
        extra="forbid"
    )

    path_id: PositiveId
    path_title: NonEmptyString
    roadmap_result: RoadmapAgentResult
    child_roadmaps: list[
        GeneratedChildRoadmap
    ] = Field(
        default_factory=list
    )

    @model_validator(mode="after")
    def validate_path_package(
        self,
    ) -> "GeneratedPathRoadmap":
        if (
            self.roadmap_result.roadmap_topic.casefold()
            != self.path_title.casefold()
        ):
            raise ValueError(
                "Generated roadmap topic must match its scope path title."
            )

        expected_children: dict[
            tuple[int, int],
            str,
        ] = {}

        for node in self.roadmap_result.nodes:
            if node.type != "choice":
                continue

            for choice in node.choices:
                if not choice.separate_roadmap:
                    continue

                expected_children[
                    (
                        node.node_id,
                        choice.choice_id,
                    )
                ] = choice.title

        seen_children: set[
            tuple[int, int]
        ] = set()

        for child in self.child_roadmaps:
            child_key = (
                child.parent_node_id,
                child.choice_id,
            )

            if child_key in seen_children:
                raise ValueError(
                    "Duplicate child roadmap relationship."
                )

            expected_title = (
                expected_children.get(
                    child_key
                )
            )

            if expected_title is None:
                raise ValueError(
                    "Child roadmap does not match a parent choice with separate_roadmap=true."
                )

            if (
                child.choice_title.casefold()
                != expected_title.casefold()
            ):
                raise ValueError(
                    "Child roadmap choice_title does not match the parent choice title."
                )

            seen_children.add(
                child_key
            )

        if seen_children != set(
            expected_children
        ):
            raise ValueError(
                "Every parent choice with separate_roadmap=true must have exactly one child roadmap."
            )

        return self