# Learning Workflow API request and response data shapes.
from typing import (
    Annotated,
    Any,
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

PositiveNodeId = Annotated[
    int,
    Field(gt=0),
]

Difficulty = Literal[
    "beginner",
    "intermediate",
    "advanced",
]

LearningReviewStage = Literal[
    "tutorial",
    "quiz",
    "project",
    "external_resources",
]

EditableLearningStage = Literal[
    "quiz",
    "project",
    "external_resources",
]

QuizQuestionType = Literal[
    "conceptual",
    "code_analysis",
    "calculation",
    "applied_reasoning",
]

QuizOptionKey = Literal[
    "A",
    "B",
    "C",
    "D",
]

PracticalActivityType = Literal[
    "project",
    "exercises",
]

ExternalResourceType = Literal[
    "official_documentation",
    "github_repository",
    "technical_reference",
]

TutorialResourceType = Literal[
    "video",
    "playlist",
]


# =====================================================================
# LEARNING WORKFLOW INPUT
# Laravel -> FastAPI
# =====================================================================

class LearningNodeRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    id: PositiveNodeId
    title: NonEmptyString
    description: NonEmptyString | None = None
    difficulty: Difficulty | None = None


class StartLearningWorkflowRequest(BaseModel):
    """
    Data Laravel sends when starting learning-material
    generation for one roadmap node.
    """

    model_config = ConfigDict(
        extra="forbid"
    )
    roadmap_id: int = Field(gt=0)
    roadmap_topic: NonEmptyString
    version_id: int = Field(gt=0)
    version_number: NonEmptyString
    node: LearningNodeRequest
    prerequisite_nodes: list[
        LearningNodeRequest
    ] = Field(
        default_factory=list
    )

    @model_validator(
        mode="after"
    )
    def validate_learning_context(
        self,
    ) -> "StartLearningWorkflowRequest":

        prerequisite_ids = [
            prerequisite.id
            for prerequisite
            in self.prerequisite_nodes
        ]

        if (
            len(set(prerequisite_ids))
            != len(prerequisite_ids)
        ):
            raise ValueError(
                "Prerequisite node IDs must "
                "be unique."
            )

        if self.node.id in prerequisite_ids:
            raise ValueError(
                "The current roadmap node cannot "
                "also be one of its prerequisites."
            )

        return self


# =====================================================================
# QUIZ EDIT INPUT
# Laravel -> FastAPI
# =====================================================================

class QuizOptionEditRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    key: QuizOptionKey
    text: NonEmptyString

class QuizQuestionEditRequest(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    question_id: NonEmptyString
    question_type: QuizQuestionType
    question: NonEmptyString
    options: list[
        QuizOptionEditRequest
    ] = Field(
        min_length=4,
        max_length=4,
    )
    correct_option: QuizOptionKey
    correct_answer_explanation: (
        NonEmptyString
    )
    points: Literal[10] = 10
    @model_validator(
        mode="after"
    )
    def validate_question(
        self,
    ) -> "QuizQuestionEditRequest":

        option_keys = [
            option.key
            for option
            in self.options
        ]

        if set(option_keys) != {
            "A",
            "B",
            "C",
            "D",
        }:
            raise ValueError(
                "Quiz question options must "
                "contain exactly A, B, C, and D."
            )

        normalized_option_texts = [
            option.text.strip().lower()
            for option
            in self.options
        ]

        if (
            len(
                set(
                    normalized_option_texts
                )
            )
            != len(
                normalized_option_texts
            )
        ):
            raise ValueError(
                "Quiz question options must "
                "have unique answers."
            )

        return self


class QuizEditRequest(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: PositiveNodeId
    tutorial_resource_id: NonEmptyString
    questions: list[
        QuizQuestionEditRequest
    ] = Field(
        min_length=10,
        max_length=10,
    )
    max_score: Literal[100] = 100
    @model_validator(
        mode="after"
    )
    def validate_quiz(
        self,
    ) -> "QuizEditRequest":

        question_ids = [
            question.question_id
            for question
            in self.questions
        ]

        if (
            len(set(question_ids))
            != len(question_ids)
        ):
            raise ValueError(
                "Quiz question IDs must "
                "be unique."
            )
        return self

# =====================================================================
# PROJECT EDIT INPUT
# Laravel -> FastAPI
# =====================================================================

class ProjectEditRequest(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )
    node_id: PositiveNodeId
    tutorial_resource_id: NonEmptyString
    activity_type: PracticalActivityType
    title: NonEmptyString
    overview: NonEmptyString
    activity_context: NonEmptyString
    learning_objective: NonEmptyString
    tasks: list[
        NonEmptyString
    ] = Field(
        min_length=3
    )

    technical_requirements: list[
        NonEmptyString
    ] = Field(
        min_length=1
    )

    constraints: list[
        NonEmptyString
    ] = Field(
        min_length=1
    )

    deliverables: list[
        NonEmptyString
    ] = Field(
        min_length=1
    )

    acceptance_criteria: list[
        NonEmptyString
    ] = Field(
        min_length=3
    )

    @model_validator(
        mode="after"
    )
    def validate_unique_items(
        self,
    ) -> "ProjectEditRequest":

        list_fields = [
            "tasks",
            "technical_requirements",
            "constraints",
            "deliverables",
            "acceptance_criteria",
        ]

        for field_name in list_fields:

            items = getattr(
                self,
                field_name,
            )

            normalized_items = [
                item.strip().lower()
                for item
                in items
            ]

            if (
                len(set(normalized_items))
                != len(normalized_items)
            ):
                raise ValueError(
                    f"'{field_name}' must not "
                    "contain duplicate items."
                )

        return self
# =====================================================================
# EXTERNAL RESOURCES EDIT INPUT
# Laravel -> FastAPI
# =====================================================================
class ExternalResourceEditRequest(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    title: NonEmptyString
    url: NonEmptyString
    resource_type: ExternalResourceType
    source_name: NonEmptyString
    description: NonEmptyString
    why_recommended: NonEmptyString

class ExternalResourcesEditRequest(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )
    node_id: PositiveNodeId
    resources: list[
        ExternalResourceEditRequest
    ] = Field(
        max_length=3
    )

    @model_validator(
        mode="after"
    )
    def validate_unique_urls(
        self,
    ) -> "ExternalResourcesEditRequest":

        urls = [
            resource.url
            for resource
            in self.resources
        ]

        if (
            len(set(urls))
            != len(urls)
        ):
            raise ValueError(
                "External resource URLs must "
                "be unique."
            )

        return self


# =====================================================================
# MANAGER REVIEW DECISION
# Laravel -> FastAPI
# =====================================================================

class LearningManagerDecisionRequest(BaseModel):
    """
    Decision Laravel sends while the manager
    reviews generated learning material.
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

    stage: LearningReviewStage | None = None
    data: dict[
        str,
        Any,
    ] | None = None
    feedback: NonEmptyString | None = None
    @model_validator(
        mode="after"
    )
    def validate_decision(
        self,
    ) -> "LearningManagerDecisionRequest":

        # -------------------------------------------------------------
        # APPROVE
        # -------------------------------------------------------------

        if self.action == "approve":
            if self.stage is not None:
                raise ValueError(
                    "Approve must not contain "
                    "a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Approve must not contain "
                    "edited data."
                )

            if self.feedback is not None:
                raise ValueError(
                    "Approve must not contain "
                    "feedback."
                )

            return self

        # -------------------------------------------------------------
        # REJECT
        # -------------------------------------------------------------

        if self.action == "reject":

            if self.stage is not None:
                raise ValueError(
                    "Reject must not contain "
                    "a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Reject must not contain "
                    "edited data."
                )

            return self

        # -------------------------------------------------------------
        # REGENERATE
        # -------------------------------------------------------------

        if self.action == "regenerate":

            if self.stage is None:
                raise ValueError(
                    "Regenerate must contain "
                    "a stage."
                )

            if self.data is not None:
                raise ValueError(
                    "Regenerate must not contain "
                    "edited data."
                )

            return self

        # -------------------------------------------------------------
        # EDIT
        # -------------------------------------------------------------

        if self.stage is None:
            raise ValueError(
                "Edit must contain a stage."
            )

        if self.stage == "tutorial":
            raise ValueError(
                "Tutorial cannot be edited. "
                "Use regenerate instead."
            )

        if self.stage not in {
            "quiz",
            "project",
            "external_resources",
        }:
            raise ValueError(
                "Invalid learning edit stage."
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
        # QUIZ EDIT
        # -------------------------------------------------------------

        if self.stage == "quiz":

            expected_fields = {
                "quiz_result",
            }

            if (
                set(self.data.keys())
                != expected_fields
            ):
                raise ValueError(
                    "Quiz edit data must contain "
                    "only 'quiz_result'."
                )

            QuizEditRequest.model_validate(
                self.data[
                    "quiz_result"
                ]
            )

            return self

        # -------------------------------------------------------------
        # PROJECT EDIT
        # -------------------------------------------------------------

        if self.stage == "project":

            expected_fields = {
                "project_result",
            }

            if (
                set(self.data.keys())
                != expected_fields
            ):
                raise ValueError(
                    "Project edit data must contain "
                    "only 'project_result'."
                )

            ProjectEditRequest.model_validate(
                self.data[
                    "project_result"
                ]
            )

            return self

        # -------------------------------------------------------------
        # EXTERNAL RESOURCES EDIT
        # -------------------------------------------------------------

        expected_fields = {
            "external_resources_result",
        }

        if (
            set(self.data.keys())
            != expected_fields
        ):
            raise ValueError(
                "External resources edit data must "
                "contain only "
                "'external_resources_result'."
            )

        ExternalResourcesEditRequest.model_validate(
            self.data[
                "external_resources_result"
            ]
        )

        return self


# =====================================================================
# TUTORIAL RESPONSE MODELS
# FastAPI -> Laravel
# =====================================================================
class SelectedTutorialResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    resource_type: TutorialResourceType
    resource_id: NonEmptyString
    title: NonEmptyString
    url: NonEmptyString
    channel_name: NonEmptyString
    selection_reason: NonEmptyString

class TutorialCoverageResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    summary: NonEmptyString
    covered_concepts: list[NonEmptyString]
    practical_skills: list[ NonEmptyString]
    assumed_knowledge: list[NonEmptyString]
    not_covered_node_concepts: list[ NonEmptyString ]


class TutorialResultResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    node_id: PositiveNodeId
    status: Literal[
        "ready",
        "learning_failed",
    ]

    tutorial: (
        SelectedTutorialResponse
        | None
    ) = None

    coverage: (
        TutorialCoverageResponse
        | None
    ) = None

    failure_reason: str | None = None

# =====================================================================
# QUIZ RESPONSE MODELS
# FastAPI -> Laravel
# =====================================================================
class QuizOptionResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )
    key: QuizOptionKey
    text: NonEmptyString


class QuizQuestionResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )
    question_id: NonEmptyString
    question_type: QuizQuestionType
    question: NonEmptyString
    options: list[QuizOptionResponse]
    correct_option: QuizOptionKey
    correct_answer_explanation: (NonEmptyString)
    points: Literal[10]

class QuizResultResponse(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )
    node_id: PositiveNodeId
    tutorial_resource_id: NonEmptyString
    questions: list[QuizQuestionResponse]
    max_score: Literal[100]


# =====================================================================
# PROJECT RESPONSE MODELS
# FastAPI -> Laravel
# =====================================================================

class ProjectResultResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: PositiveNodeId

    tutorial_resource_id: NonEmptyString

    activity_type: PracticalActivityType

    title: NonEmptyString

    overview: NonEmptyString

    activity_context: NonEmptyString

    learning_objective: NonEmptyString

    tasks: list[
        NonEmptyString
    ]

    technical_requirements: list[
        NonEmptyString
    ]

    constraints: list[
        NonEmptyString
    ]

    deliverables: list[
        NonEmptyString
    ]

    acceptance_criteria: list[
        NonEmptyString
    ]


# =====================================================================
# EXTERNAL RESOURCES RESPONSE MODELS
# FastAPI -> Laravel
# =====================================================================

class ExternalResourceResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    title: NonEmptyString

    url: NonEmptyString

    resource_type: ExternalResourceType

    source_name: NonEmptyString

    description: NonEmptyString

    why_recommended: NonEmptyString


class ExternalResourcesResultResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: PositiveNodeId

    resources: list[
        ExternalResourceResponse
    ]


# =====================================================================
# LEARNING WORKFLOW RESPONSE
# FastAPI -> Laravel
# =====================================================================

class LearningWorkflowDataResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    tutorial_result: TutorialResultResponse

    quiz_result: QuizResultResponse

    project_result: ProjectResultResponse

    external_resources_result: (
        ExternalResourcesResultResponse
    )


LearningWorkflowResponseStatus = Literal[
    "awaiting_review",
    "ready",
    "rejected",
    "failed",
]


class LearningWorkflowResponse(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    thread_id: NonEmptyString

    status: LearningWorkflowResponseStatus

    review_stage: (
        Literal["full_review"]
        | None
    ) = None

    data: (
        LearningWorkflowDataResponse
        | None
    ) = None

    rejection_feedback: str | None = None

    error: str | None = None