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

SubmissionType = Literal[
    "file",
    "github",
    "file and github"
]

PracticalActivityType = Literal[
    "project",
    "exercises",
]

AssessmentCriterionStatus = Literal[
    "met",
    "mostly_met",
    "partially_met",
    "minimally_met",
    "not_met",
    "not_verifiable",
]


# =====================================================================
# START PROJECT ASSESSMENT REQUEST METADATA
# =====================================================================

class StartProjectAssessmentRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    learning_thread_id: NonEmptyString
    submission_type: SubmissionType
    repository_url: NonEmptyString | None = None
    student_note: NonEmptyString | None = None
    @model_validator(
    mode="after"
)
    def validate_submission_type(
    self,
    ) -> "StartProjectAssessmentRequest":

        if self.submission_type in {
            "github",
            "file and github",
        }:

            if self.repository_url is None:
                raise ValueError(
                    "GitHub submission requires "
                    "repository_url."
                )

        if self.submission_type == "file":

            if self.repository_url is not None:
                raise ValueError(
                    "File-only submission must not "
                    "contain repository_url."
                )

        return self


# =====================================================================
# SUBMISSION RESPONSE
# =====================================================================

class ProjectSubmissionResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    submission_type: SubmissionType
    submission_file_name: str | None = None
    repository_url: str | None = None
    repository_default_branch: str | None = None


# =====================================================================
# CRITERION ASSESSMENT RESPONSE
# =====================================================================

class CriterionAssessmentResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    criterion_id: NonEmptyString
    criterion: NonEmptyString
    status: AssessmentCriterionStatus
    evidence: NonEmptyString
    feedback: NonEmptyString


# =====================================================================
# BONUS ASSESSMENT RESPONSE
# =====================================================================

class BonusCategoryAssessmentResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    score: int = Field(ge=0,le=5,)
    evidence: list[
        NonEmptyString
    ] = Field(
        default_factory=list
    )
    explanation: NonEmptyString


# =====================================================================
# PROJECT ASSESSMENT RESULT RESPONSE
# =====================================================================

class ProjectAssessmentResultResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: int = Field(
        gt=0
    )

    tutorial_resource_id: NonEmptyString
    activity_type: PracticalActivityType
    criterion_assessments: list[
        CriterionAssessmentResponse
    ] = Field(
        min_length=1
    )

    meaningful_extra_features: (
        BonusCategoryAssessmentResponse
    )
    creativity_and_innovation: (
        BonusCategoryAssessmentResponse
    )
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


# =====================================================================
# ASSESSMENT SCORE RESPONSE
# =====================================================================

class AssessmentScoreResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    base_score: float = Field(ge=0,le=100,)
    bonus_score: int = Field(ge=0,le=10,)
    total_score: float = Field(ge=0,le=110,)
    max_base_score: Literal[100]
    max_bonus_score: Literal[10]

# =====================================================================
# PROJECT ASSESSMENT DATA RESPONSE
# =====================================================================

class ProjectAssessmentDataResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    learning_thread_id: NonEmptyString
    submission: ProjectSubmissionResponse
    project_assessment_result: (
        ProjectAssessmentResultResponse
    )
    assessment_score: AssessmentScoreResponse

# =====================================================================
# PROJECT ASSESSMENT WORKFLOW RESPONSE
# =====================================================================

ProjectAssessmentPublicStatus = Literal[
    "ready",
    "failed",
]


class ProjectAssessmentWorkflowResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    thread_id: NonEmptyString
    status: ProjectAssessmentPublicStatus
    data: ProjectAssessmentDataResponse | None = None
    error: str | None = None