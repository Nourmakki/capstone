from typing import Annotated, Literal
from pydantic import (
    BaseModel,
    ConfigDict,
    Field,
    StringConstraints,
    model_validator,
)
from learning_workflow.schema import ( PracticalActivityType,)

# =====================================================================
# COMMON VALIDATION TYPES
# =====================================================================
NonEmptyString = Annotated[
    str,
    StringConstraints(
        strip_whitespace=True,
        min_length=1,
    ),
]

PositiveNodeId = Annotated[
    int,
    Field(gt=0),
]

# =====================================================================
# PROJECT ASSESSMENT AGENT FINAL RESULT
# =====================================================================

AssessmentCriterionStatus = Literal[
    "met",
    "mostly_met",
    "partially_met",
    "minimally_met",
    "not_met",
    "not_verifiable",
]


class CriterionAssessment(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    criterion_id: NonEmptyString
    criterion: NonEmptyString
    status: AssessmentCriterionStatus
    evidence: NonEmptyString
    feedback: NonEmptyString


class BonusCategoryAssessment(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    score: int = Field(ge=0,le=5)
    evidence: list[
        NonEmptyString
    ] = Field(
        default_factory=list
    )
    explanation: NonEmptyString

    @model_validator(
        mode="after"
    )
    def validate_bonus_evidence(
        self,
    ) -> "BonusCategoryAssessment":

        if (
            self.score > 0
            and not self.evidence
        ):
            raise ValueError(
                "A positive bonus score must "
                "contain supporting evidence."
            )

        return self


class ProjectAssessmentResult(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: PositiveNodeId
    tutorial_resource_id: NonEmptyString
    activity_type: PracticalActivityType
    criterion_assessments: list[
        CriterionAssessment
    ] = Field(
        min_length=1
    )
    meaningful_extra_features: (BonusCategoryAssessment)
    creativity_and_innovation: (BonusCategoryAssessment)
    constraint_violations: list[
        NonEmptyString
    ] = Field(
        default_factory=list
    )
    strengths: list[
        NonEmptyString
    ] = Field(
        default_factory=list
    )

    improvements: list[
        NonEmptyString
    ] = Field(
        default_factory=list
    )

    overall_feedback: NonEmptyString

    @model_validator(
        mode="after"
    )
    def validate_unique_criterion_ids(
        self,
    ) -> "ProjectAssessmentResult":

        criterion_ids = [
            assessment.criterion_id
            for assessment
            in self.criterion_assessments
        ]

        if (
            len(set(criterion_ids))
            != len(criterion_ids)
        ):
            raise ValueError(
                "Criterion assessment IDs "
                "must be unique."
            )

        return self
