# API request and response data shapes.

from typing import Annotated, Any, Literal

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


ReviewStage = Literal[
    "trends",
    "nodes",
    "placement",
]


# =====================================================================
# ROADMAP INPUT
# Laravel -> FastAPI
# =====================================================================

class RoadmapNodeRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    id: int = Field(gt=0)
    title: NonEmptyString
    description: str | None = None


class RoadmapEdgeRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    from_node_id: int = Field(gt=0)
    to_node_id: int = Field(gt=0)


class StartWorkflowRequest(BaseModel):
    """
    Data Laravel sends when starting
    a new roadmap AI workflow.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    roadmap_id: int = Field(gt=0)
    roadmap_topic: NonEmptyString

    version_id: int = Field(gt=0)
    version_number: NonEmptyString

    nodes: list[RoadmapNodeRequest]
    edges: list[RoadmapEdgeRequest]

    @model_validator(mode="after")
    def validate_roadmap_graph(
        self,
    ) -> "StartWorkflowRequest":

        node_ids = [
            node.id
            for node in self.nodes
        ]

        # -------------------------------------------------------------
        # DUPLICATE NODE IDS
        # -------------------------------------------------------------

        if len(node_ids) != len(set(node_ids)):
            raise ValueError(
                "Roadmap node IDs must be unique."
            )

        valid_node_ids = set(node_ids)

        # -------------------------------------------------------------
        # EDGE VALIDATION
        # -------------------------------------------------------------

        seen_edges: set[
            tuple[int, int]
        ] = set()

        for edge in self.edges:
            if (
                edge.from_node_id not in valid_node_ids
                or edge.to_node_id not in valid_node_ids
            ):
                raise ValueError(
                    "Every roadmap edge must "
                    "reference existing node IDs."
                )

            if edge.from_node_id == edge.to_node_id:
                raise ValueError(
                    "A roadmap node cannot have "
                    "an edge to itself."
                )

            edge_pair = (
                edge.from_node_id,
                edge.to_node_id,
            )

            if edge_pair in seen_edges:
                raise ValueError(
                    "Duplicate roadmap edges "
                    "are not allowed."
                )

            seen_edges.add(edge_pair)

        # -------------------------------------------------------------
        # ISOLATED NODE VALIDATION
        # -------------------------------------------------------------

        if len(self.nodes) > 1:
            connected_node_ids: set[int] = set()

            for edge in self.edges:
                connected_node_ids.add(
                    edge.from_node_id
                )

                connected_node_ids.add(
                    edge.to_node_id
                )

            isolated_node_ids = (
                valid_node_ids
                - connected_node_ids
            )

            if isolated_node_ids:
                raise ValueError(
                    "Every roadmap node must participate "
                    "in at least one edge. "
                    f"Isolated node IDs: "
                    f"{sorted(isolated_node_ids)}"
                )

        # -------------------------------------------------------------
        # CYCLE VALIDATION
        # -------------------------------------------------------------

        adjacency: dict[int, list[int]] = {
            node_id: []
            for node_id in valid_node_ids
        }

        for edge in self.edges:
            adjacency[
                edge.from_node_id
            ].append(
                edge.to_node_id
            )

        visiting: set[int] = set()
        visited: set[int] = set()

        def has_cycle(
            node_id: int,
        ) -> bool:

            if node_id in visiting:
                return True

            if node_id in visited:
                return False

            visiting.add(node_id)

            for next_node_id in adjacency[node_id]:
                if has_cycle(next_node_id):
                    return True

            visiting.remove(node_id)
            visited.add(node_id)

            return False

        for node_id in valid_node_ids:
            if has_cycle(node_id):
                raise ValueError(
                    "Roadmap edges must not "
                    "contain a cycle."
                )

        return self


# =====================================================================
# STRICT EDIT INPUT MODELS
# Laravel -> FastAPI after admin edits AI output
# =====================================================================

class TrendSourceEditRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    source_name: NonEmptyString
    url: NonEmptyString


class TrendEditRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    title: NonEmptyString
    description: NonEmptyString

    confidence_score: float = Field(
        ge=0,
        le=100,
    )

    sources: list[TrendSourceEditRequest]


class NodeSuggestionEditRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    title: NonEmptyString
    description: NonEmptyString
    difficulty: NonEmptyString
    based_on_trend: NonEmptyString


class PlacementSuggestionEditRequest(BaseModel):
    model_config = ConfigDict(extra="forbid")

    suggested_node_id: int = Field(gt=0)

    suggested_node_title: NonEmptyString

    direct_prerequisite_node_ids: list[int]
    direct_successor_node_ids: list[int]
    recommended_predecessor_node_ids: list[int]

    explanation: NonEmptyString


class ExistingEdgeRemovalEditRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    from_node_id: int = Field(gt=0)

    to_node_id: int = Field(gt=0)


# =====================================================================
# ADMIN REVIEW DECISION
# Laravel -> FastAPI
# =====================================================================

class AdminDecisionRequest(BaseModel):
    """
    Decision Laravel sends when the admin
    is reviewing the complete AI proposal.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    action: Literal[
        "approve",
        "edit",
        "regenerate",
        "reject",
    ]

    stage: ReviewStage | None = None

    data: dict[str, Any] | None = None

    regenerate_after_edit: bool = False

    feedback: NonEmptyString | None = None

    @model_validator(mode="after")
    def validate_decision(
        self,
    ) -> "AdminDecisionRequest":

        # -------------------------------------------------------------
        # APPROVE
        # -------------------------------------------------------------

        if self.action == "approve":
            if self.stage is not None:
                raise ValueError(
                    "Approve must not contain a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Approve must not contain edited data."
                )

            if self.regenerate_after_edit:
                raise ValueError(
                    "Approve cannot regenerate "
                    "outputs after editing."
                )

            if self.feedback is not None:
                raise ValueError(
                    "Approve must not contain feedback."
                )

            return self

        # -------------------------------------------------------------
        # REJECT
        # -------------------------------------------------------------

        if self.action == "reject":
            if self.stage is not None:
                raise ValueError(
                    "Reject must not contain a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Reject must not contain edited data."
                )

            if self.regenerate_after_edit:
                raise ValueError(
                    "Reject cannot regenerate "
                    "outputs after editing."
                )

            return self

        # -------------------------------------------------------------
        # REGENERATE
        # -------------------------------------------------------------

        if self.action == "regenerate":
            if self.stage is None:
                raise ValueError(
                    "Regenerate must contain a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Regenerate must not contain "
                    "edited data."
                )

            if self.regenerate_after_edit:
                raise ValueError(
                    "regenerate_after_edit is only "
                    "used with edit actions."
                )

            if self.feedback is not None:
                raise ValueError(
                    "Regenerate must not contain feedback."
                )

            return self

        # -------------------------------------------------------------
        # EDIT
        # -------------------------------------------------------------

        if self.stage is None:
            raise ValueError(
                "Edit must contain a stage."
            )

        if not isinstance(
            self.data,
            dict,
        ):
            raise ValueError(
                "Edit must contain a data object."
            )

        if self.feedback is not None:
            raise ValueError(
                "Edit must not contain feedback."
            )

        # -------------------------------------------------------------
        # EXPECTED DATA FIELDS
        # -------------------------------------------------------------

        if self.stage == "trends":
            expected_fields = {
                "trends",
            }

        elif self.stage == "nodes":
            expected_fields = {
                "node_suggestions",
            }

        else:
            expected_fields = {
                "placement_suggestions",
                "existing_edges_to_remove",
            }

        if set(self.data.keys()) != expected_fields:
            raise ValueError(
                "Edited data does not match "
                f"the '{self.stage}' stage."
            )

        # -------------------------------------------------------------
        # GENERAL LIST VALIDATION
        # -------------------------------------------------------------

        for field_name in expected_fields:
            items = self.data[field_name]

            if not isinstance(
                items,
                list,
            ):
                raise ValueError(
                    f"'{field_name}' must be a list."
                )

            for item in items:
                if (
                    not isinstance(item, dict)
                    or not item
                ):
                    raise ValueError(
                        f"'{field_name}' must contain "
                        "non-empty objects."
                    )

        # -------------------------------------------------------------
        # TREND EDIT VALIDATION
        # -------------------------------------------------------------

        if self.stage == "trends":
            trends = self.data["trends"]

            if not trends:
                raise ValueError(
                    "'trends' must not be empty."
                )

            for trend in trends:
                TrendEditRequest.model_validate(trend)

        # -------------------------------------------------------------
        # NODE EDIT VALIDATION
        # -------------------------------------------------------------

        elif self.stage == "nodes":
            node_suggestions = self.data["node_suggestions"]

            if not node_suggestions:
                raise ValueError(
                    "'node_suggestions' must "
                    "not be empty."
                )

            for node in node_suggestions:
                NodeSuggestionEditRequest.model_validate(
                    node
                )

        # -------------------------------------------------------------
        # PLACEMENT EDIT VALIDATION
        # -------------------------------------------------------------

        elif self.stage == "placement":
            placements = self.data["placement_suggestions"]

            edges_to_remove = self.data["existing_edges_to_remove"]

            if (
                not placements
                and not edges_to_remove
            ):
                raise ValueError(
                    "Placement edit must contain "
                    "at least one edited placement "
                    "or edge removal."
                )

            for placement in placements:
                PlacementSuggestionEditRequest.model_validate(placement)

            for edge in edges_to_remove:
                ExistingEdgeRemovalEditRequest.model_validate(edge)

            if self.regenerate_after_edit:
                raise ValueError(
                    "Placement has no downstream "
                    "output to regenerate."
                )

        return self


# =====================================================================
# WORKFLOW RESPONSE MODELS
# FastAPI -> Laravel
# =====================================================================

class TrendSourceResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    source_name: NonEmptyString
    url: NonEmptyString


class TrendResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    title: NonEmptyString
    description: NonEmptyString

    confidence_score: float = Field(ge=0,le=100,)

    sources: list[TrendSourceResponse]


class NodeSuggestionResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    title: NonEmptyString
    description: NonEmptyString
    difficulty: NonEmptyString
    based_on_trend: NonEmptyString


class PlacementSuggestionResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    suggested_node_id: int = Field(gt=0)

    suggested_node_title: NonEmptyString

    direct_prerequisite_node_ids: list[int]
    direct_successor_node_ids: list[int]
    recommended_predecessor_node_ids: list[int]

    explanation: NonEmptyString


class ExistingEdgeRemovalResponse(BaseModel):
    model_config = ConfigDict(extra="forbid")

    from_node_id: int = Field(gt=0)

    to_node_id: int = Field(gt=0)


class WorkflowProposalResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    trends: list[TrendResponse]

    node_suggestions: list[NodeSuggestionResponse]

    placement_suggestions: list[PlacementSuggestionResponse]

    existing_edges_to_remove: list[ExistingEdgeRemovalResponse]


WorkflowResponseStatus = Literal[
    "awaiting_review",
    "ready_for_commit",
    "rejected",
    "failed",
]


class WorkflowResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    thread_id: NonEmptyString

    status: WorkflowResponseStatus

    review_stage: (Literal["full_review"]| None) = None

    data: (WorkflowProposalResponse| None) = None

    rejection_feedback: str | None = None
    error: str | None = None