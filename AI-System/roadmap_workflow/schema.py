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
# ROADMAP CHOICE
# =====================================================================

class RoadmapChoice(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    choice_id: PositiveId
    title: NonEmptyString
    description: NonEmptyString
    recommended: bool
    recommendation_reason: NonEmptyString | None = None
    @model_validator(
        mode="after"
    )
    def validate_recommendation_reason(
        self,
    ) -> "RoadmapChoice":

        if (
            self.recommended
            and self.recommendation_reason is None
        ):
            raise ValueError(
                "A recommended roadmap choice "
                "must include a recommendation_reason."
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
    prerequisite_node_ids: list[
        PositiveId
    ] = Field(
        default_factory=list
    )
    learning_outcomes: list[
        NonEmptyString
    ] = Field(
        min_length=1
    )
# =====================================================================
# STANDARD ROADMAP NODE
# =====================================================================

class StandardRoadmapNode(BaseRoadmapNode):
    type: Literal["standard"]

# =====================================================================
# CHOICE ROADMAP NODE
# =====================================================================

class ChoiceRoadmapNode(BaseRoadmapNode):
    type: Literal["choice"]
    choices: list[
        RoadmapChoice
    ] = Field(min_length=2)
    @model_validator(
        mode="after"
    )
    def validate_choices(
        self,
    ) -> "ChoiceRoadmapNode":

        choice_ids = [
            choice.choice_id
            for choice in self.choices
        ]

        if (
            len(choice_ids)
            != len(set(choice_ids))
        ):
            raise ValueError(
                "Roadmap choice IDs must be "
                "unique within a choice node."
            )

        normalized_titles = [
            choice.title.casefold()
            for choice in self.choices
        ]

        if (
            len(normalized_titles)
            != len(set(normalized_titles))
        ):
            raise ValueError(
                "Roadmap choices must have "
                "unique titles within a choice node."
            )

        recommended_choices = [
            choice
            for choice in self.choices
            if choice.recommended
        ]

        if len(recommended_choices) != 1:
            raise ValueError(
                "A choice roadmap node must "
                "contain exactly one "
                "recommended choice."
            )

        return self


# =====================================================================
# ROADMAP NODE UNION
# =====================================================================
RoadmapNode = Annotated[
    StandardRoadmapNode
    | ChoiceRoadmapNode,
    Field( discriminator="type" ),
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
    nodes: list[
        RoadmapNode
    ] = Field(
        min_length=2
    )
    freshness_summary: NonEmptyString
    @model_validator(
        mode="after"
    )
    def validate_roadmap_structure(
        self,
    ) -> "RoadmapAgentResult":

        node_ids = [
            node.node_id
            for node in self.nodes
        ]

        if (
            len(node_ids)
            != len(set(node_ids))
        ):
            raise ValueError(
                "Roadmap node IDs must be unique."
            )

        node_orders = [
            node.order
            for node in self.nodes
        ]

        if (
            len(node_orders)
            != len(set(node_orders))
        ):
            raise ValueError(
                "Roadmap node orders must be unique."
            )

        node_by_id = {
            node.node_id: node
            for node in self.nodes
        }

        for node in self.nodes:

            if (
                len(
                    node.prerequisite_node_ids
                )
                != len(
                    set(
                        node.prerequisite_node_ids
                    )
                )
            ):
                raise ValueError(
                    f"Roadmap node {node.node_id} "
                    "contains duplicate prerequisites."
                )

            for prerequisite_id in (
                node.prerequisite_node_ids
            ):

                if prerequisite_id not in node_by_id:
                    raise ValueError(
                        f"Roadmap node {node.node_id} "
                        "references a prerequisite "
                        f"node that does not exist: "
                        f"{prerequisite_id}."
                    )

                if prerequisite_id == node.node_id:
                    raise ValueError(
                        f"Roadmap node {node.node_id} "
                        "cannot depend on itself."
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
                        f"Roadmap node {node.node_id} "
                        "has a prerequisite that does "
                        "not appear earlier in the "
                        "roadmap."
                    )

        return self