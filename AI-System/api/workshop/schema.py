from datetime import date
from typing import (
    Annotated,
    Literal,
)

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


# =====================================================================
# START WORKFLOW REQUEST
# Laravel -> FastAPI
# =====================================================================

class StartWorkshopWorkflowRequest(
    BaseModel
):
    """
    Input Laravel sends when starting a new
    Workshop Recommendation Workflow.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    roadmap_topic: NonEmptyString
    student_location: (NonEmptyString | None) = None


# =====================================================================
# WORKSHOP RESPONSE MODEL
# FastAPI -> Laravel
# =====================================================================

WorkshopCostType = Literal[
    "free",
    "paid",
    "not_specified",
]


class WorkshopResponse(
    BaseModel
):
    """
    One workshop returned by the Workshop Workflow.

    The same model is used for:
    - workshops waiting for manager review
    - workshops approved for Laravel to commit
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    title: NonEmptyString

    organizer: (
        NonEmptyString | None
    ) = None

    speaker: (
        NonEmptyString | None
    ) = None

    date: date

    time: (
        NonEmptyString | None
    ) = None

    location: NonEmptyString

    cost_type: WorkshopCostType

    price: (
        NonEmptyString | None
    ) = None

    link: NonEmptyString

    @model_validator(mode="after")
    def validate_cost(
        self,
    ) -> "WorkshopResponse":
        """
        Keep the API response consistent with the
        Workshop Workflow validation rules.
        """

        if (
            self.cost_type == "paid"
            and self.price is None
        ):
            raise ValueError(
                "A paid workshop must "
                "contain a price."
            )

        if (
            self.cost_type
            == "not_specified"
            and self.price is not None
        ):
            raise ValueError(
                "A workshop with unspecified cost "
                "must have a null price."
            )

        return self


# =====================================================================
# WORKSHOP DATA RESPONSE
# FastAPI -> Laravel
# =====================================================================

class WorkshopDataResponse(
    BaseModel
):
    """
    Workshop data exposed through the API.

    While awaiting review:
        contains proposed workshops.

    When ready_for_commit:
        contains only approved workshops.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    workshops: list[
        WorkshopResponse
    ]


# =====================================================================
# SELECTIVE APPROVAL DATA
# Laravel -> FastAPI
# =====================================================================

class WorkshopApprovalData(
    BaseModel
):
    """
    Optional data supplied with an approve decision
    when the manager wants to approve only some of
    the proposed workshops.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    approved_workshop_links: list[
        NonEmptyString
    ] = Field(
        min_length=1,
        max_length=6,
    )

    @model_validator(mode="after")
    def validate_unique_links(
        self,
    ) -> "WorkshopApprovalData":

        if len(
            self.approved_workshop_links
        ) != len(
            set(
                self.approved_workshop_links
            )
        ):
            raise ValueError(
                "approved_workshop_links "
                "must not contain duplicates."
            )

        return self


# =====================================================================
# MANAGER REVIEW DECISION
# Laravel -> FastAPI
# =====================================================================

class WorkshopManagerDecisionRequest(
    BaseModel
):
    """
    Decision Laravel sends while the Workshop
    Workflow is waiting for manager review.

    Supported actions:
    - approve all
    - approve selected workshops
    - reject

    Edit and regenerate are intentionally not
    supported by this workflow.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    action: Literal[
        "approve",
        "reject",
    ]

    stage: None = None

    data: (
        WorkshopApprovalData | None
    ) = None

    feedback: (
        NonEmptyString | None
    ) = None

    @model_validator(mode="after")
    def validate_decision(
        self,
    ) -> "WorkshopManagerDecisionRequest":

        # -------------------------------------------------------------
        # APPROVE
        # -------------------------------------------------------------

        if self.action == "approve":

            if self.stage is not None:
                raise ValueError(
                    "Approve must not "
                    "contain a stage."
                )

            if self.feedback is not None:
                raise ValueError(
                    "Approve must not "
                    "contain feedback."
                )

            # data=None means:
            # approve all proposed workshops.
            #
            # data containing approved_workshop_links
            # means:
            # approve only those workshops.

            return self

        # -------------------------------------------------------------
        # REJECT
        # -------------------------------------------------------------

        if self.stage is not None:
            raise ValueError(
                "Reject must not "
                "contain a stage."
            )

        if self.data is not None:
            raise ValueError(
                "Reject must not "
                "contain data."
            )

        # Feedback is optional for reject,
        # matching the current workflow.

        return self


# =====================================================================
# WORKFLOW RESPONSE
# FastAPI -> Laravel
# =====================================================================

WorkshopResponseStatus = Literal[
    "awaiting_review",
    "ready_for_commit",
    "rejected",
    "failed",
]


class WorkshopWorkflowResponse(
    BaseModel
):
    """
    Final API shape returned by the Workshop
    Workflow endpoints.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    thread_id: NonEmptyString

    status: WorkshopResponseStatus

    review_stage: (
        Literal["workshops"] | None
    ) = None

    data: (
        WorkshopDataResponse | None
    ) = None

    rejection_feedback: (
        str | None
    ) = None

    generation_attempts: int = Field(
        ge=0
    )

    error: (
        str | None
    ) = None