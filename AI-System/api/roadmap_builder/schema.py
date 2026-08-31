from typing import Annotated, Literal

from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    StringConstraints,
    model_validator,
)


NonEmptyString = Annotated[
    str,
    StringConstraints(
        strip_whitespace=True,
        min_length=1,
    ),
]


# =====================================================================
# START WORKFLOW REQUEST
# =====================================================================

class StartRoadmapBuilderRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    roadmap_topic: NonEmptyString


# =====================================================================
# SCOPE MODELS
# =====================================================================

class RoadmapScopePathModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    path_id: int = Field(gt=0)
    title: NonEmptyString
    description: NonEmptyString


class RoadmapScopeResultModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    input_topic: NonEmptyString
    is_broad_topic: bool
    paths: list[RoadmapScopePathModel]
    scope_summary: NonEmptyString

    @model_validator(mode="after")
    def validate_scope(
        self,
    ) -> "RoadmapScopeResultModel":
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
                "A broad topic must contain at least two professional paths."
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

class RoadmapChoiceModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    choice_id: int = Field(gt=0)
    title: NonEmptyString
    description: NonEmptyString
    separate_roadmap: bool
    recommended: bool
    recommendation_reason: NonEmptyString | None = None

    @model_validator(mode="after")
    def validate_recommendation(
        self,
    ) -> "RoadmapChoiceModel":
        if (
            self.recommended
            and self.recommendation_reason is None
        ):
            raise ValueError(
                "A recommended choice must contain a recommendation_reason."
            )

        return self


# =====================================================================
# ROADMAP NODES
# =====================================================================

class BaseRoadmapNodeModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: int = Field(gt=0)
    title: NonEmptyString
    description: NonEmptyString
    order: int = Field(gt=0)
    prerequisite_node_ids: list[int]
    learning_outcomes: list[NonEmptyString]


class StandardRoadmapNodeModel(
    BaseRoadmapNodeModel
):
    type: Literal["standard"]


class ChoiceRoadmapNodeModel(
    BaseRoadmapNodeModel
):
    type: Literal["choice"]
    choices: list[RoadmapChoiceModel]

    @model_validator(mode="after")
    def validate_choices(
        self,
    ) -> "ChoiceRoadmapNodeModel":
        if len(self.choices) < 2:
            raise ValueError(
                "A choice node must contain at least two choices."
            )

        choice_ids = [
            choice.choice_id
            for choice in self.choices
        ]

        if len(choice_ids) != len(
            set(choice_ids)
        ):
            raise ValueError(
                "Choice IDs must be unique within a choice node."
            )

        choice_titles = [
            choice.title.casefold()
            for choice in self.choices
        ]

        if len(choice_titles) != len(
            set(choice_titles)
        ):
            raise ValueError(
                "Choice titles must be unique within a choice node."
            )

        recommended_count = sum(
            1
            for choice in self.choices
            if choice.recommended
        )

        if recommended_count != 1:
            raise ValueError(
                "A choice node must contain exactly one recommended choice."
            )

        return self


RoadmapNodeModel = Annotated[
    StandardRoadmapNodeModel
    | ChoiceRoadmapNodeModel,
    Field(discriminator="type"),
]


# =====================================================================
# ROADMAP RESULT
# =====================================================================

class RoadmapResultModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    roadmap_topic: NonEmptyString
    title: NonEmptyString
    description: NonEmptyString
    nodes: list[RoadmapNodeModel]
    freshness_summary: NonEmptyString

    @model_validator(mode="after")
    def validate_graph(
        self,
    ) -> "RoadmapResultModel":
        if len(self.nodes) < 2:
            raise ValueError(
                "A roadmap must contain at least two nodes."
            )

        node_ids = [
            node.node_id
            for node in self.nodes
        ]

        expected_ids = list(
            range(
                1,
                len(self.nodes) + 1,
            )
        )

        if node_ids != expected_ids:
            raise ValueError(
                "Roadmap node IDs must start at 1 and be consecutive."
            )

        orders = [
            node.order
            for node in self.nodes
        ]

        expected_orders = list(
            range(
                1,
                len(self.nodes) + 1,
            )
        )

        if orders != expected_orders:
            raise ValueError(
                "Roadmap node order values must start at 1 and be consecutive."
            )

        node_lookup = {
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
                    "A roadmap node cannot contain duplicate prerequisites."
                )

            for prerequisite_id in prerequisite_ids:
                if prerequisite_id not in node_lookup:
                    raise ValueError(
                        "Every prerequisite must reference an existing roadmap node."
                    )

                if prerequisite_id == node.node_id:
                    raise ValueError(
                        "A roadmap node cannot depend on itself."
                    )

                prerequisite_node = (
                    node_lookup[
                        prerequisite_id
                    ]
                )

                if (
                    prerequisite_node.order
                    >= node.order
                ):
                    raise ValueError(
                        "A prerequisite must appear before the node that depends on it."
                    )

        return self


# =====================================================================
# CHILD ROADMAP
# =====================================================================

class ChildRoadmapModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    parent_node_id: int = Field(gt=0)
    choice_id: int = Field(gt=0)
    choice_title: NonEmptyString
    roadmap_result: RoadmapResultModel

    @model_validator(mode="after")
    def validate_child(
        self,
    ) -> "ChildRoadmapModel":
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
                        "A child roadmap cannot contain separate_roadmap=true."
                    )

        return self


# =====================================================================
# GENERATED PATH PACKAGE
# =====================================================================

class GeneratedPathRoadmapModel(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    path_id: int = Field(gt=0)
    path_title: NonEmptyString
    roadmap_result: RoadmapResultModel
    child_roadmaps: list[
        ChildRoadmapModel
    ]

    @model_validator(mode="after")
    def validate_package(
        self,
    ) -> "GeneratedPathRoadmapModel":
        if (
            self.roadmap_result.roadmap_topic.casefold()
            != self.path_title.casefold()
        ):
            raise ValueError(
                "Roadmap topic must match its scope path title."
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
                    "Child roadmap choice_title does not match the parent choice."
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


# =====================================================================
# MANAGER DECISION
# =====================================================================

ManagerEditData = (
    RoadmapScopeResultModel
    | GeneratedPathRoadmapModel
)


class RoadmapBuilderManagerDecisionRequest(
    BaseModel
):
    model_config = ConfigDict(
        extra="forbid"
    )

    action: Literal[
        "approve",
        "edit",
        "regenerate",
        "reject",
    ]

    stage: Literal[
        "scope",
        "roadmap",
    ] | None = None

    data: ManagerEditData | None = None
    feedback: NonEmptyString | None = None

    @model_validator(mode="after")
    def validate_decision(
        self,
    ) -> "RoadmapBuilderManagerDecisionRequest":
        if self.action == "approve":
            if self.stage is not None:
                raise ValueError(
                    "Approve must not contain a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Approve must not contain data."
                )

            if self.feedback is not None:
                raise ValueError(
                    "Approve must not contain feedback."
                )

            return self

        if self.action == "reject":
            if self.stage is not None:
                raise ValueError(
                    "Reject must not contain a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Reject must not contain data."
                )

            return self

        if self.action == "regenerate":
            if self.stage not in {
                "scope",
                "roadmap",
            }:
                raise ValueError(
                    "Regenerate must contain stage='scope' or stage='roadmap'."
                )

            if self.data is not None:
                raise ValueError(
                    "Regenerate must not contain data."
                )

            if self.feedback is None:
                raise ValueError(
                    "Regenerate must contain feedback."
                )

            return self

        if self.stage == "scope":
            if not isinstance(
                self.data,
                RoadmapScopeResultModel,
            ):
                raise ValueError(
                    "Scope edit data must contain a complete scope result."
                )

        elif self.stage == "roadmap":
            if not isinstance(
                self.data,
                GeneratedPathRoadmapModel,
            ):
                raise ValueError(
                    "Roadmap edit data must contain the complete current roadmap package."
                )

        else:
            raise ValueError(
                "Edit must contain stage='scope' or stage='roadmap'."
            )

        if self.feedback is not None:
            raise ValueError(
                "Edit must not contain feedback."
            )

        return self


# =====================================================================
# WORKFLOW RESPONSE DATA
# =====================================================================

class RoadmapBuilderWorkflowData(
    BaseModel
):
    model_config = ConfigDict(
        extra="forbid"
    )

    scope_result: (
        RoadmapScopeResultModel
        | None
    ) = None

    roadmap_package: (
        GeneratedPathRoadmapModel
        | None
    ) = None

    approved_roadmap_packages: (
        list[
            GeneratedPathRoadmapModel
        ]
        | None
    ) = None

    current_roadmap_number: (
        int
        | None
    ) = Field(
        default=None,
        gt=0,
    )

    total_roadmaps: (
        int
        | None
    ) = Field(
        default=None,
        gt=0,
    )


# =====================================================================
# WORKFLOW RESPONSE
# =====================================================================

RoadmapBuilderResponseStatus = Literal[
    "awaiting_review",
    "ready_for_commit",
    "rejected",
    "failed",
]


class RoadmapBuilderWorkflowResponse(
    BaseModel
):
    model_config = ConfigDict(
        extra="forbid"
    )

    thread_id: NonEmptyString
    status: RoadmapBuilderResponseStatus

    review_stage: Literal[
        "scope_review",
        "roadmap_review",
    ] | None = None

    data: (
        RoadmapBuilderWorkflowData
        | None
    ) = None

    rejection_feedback: str | None = None

    scope_generation_attempts: int = Field(
        ge=0
    )

    roadmap_generation_attempts: int = Field(
        ge=0
    )

    error: str | None = None