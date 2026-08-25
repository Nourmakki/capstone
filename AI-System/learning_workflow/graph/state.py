from typing import Literal, Optional, TypedDict


# =====================================================================
# COMMON TYPES
# =====================================================================

Difficulty = Literal[
    "beginner",
    "intermediate",
    "advanced",
]

TutorialResourceType = Literal[
    "video",
    "playlist",
]

TutorialStatus = Literal[
    "ready",
    "learning_failed",
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

AssessmentCriterionStatus = Literal[
    "met",
    "mostly_met",
    "partially_met",
    "minimally_met",
    "not_met",
    "not_verifiable",
]

# =====================================================================
# ROADMAP NODE
# =====================================================================

class LearningNode(TypedDict):
    id: int
    title: str
    description: Optional[str]
    difficulty: Optional[Difficulty]


# =====================================================================
# TUTORIAL VIDEO EVIDENCE
# =====================================================================

class TutorialChapterState(TypedDict):
    start_seconds: int
    title: str


class TutorialVideoState(TypedDict):
    video_id: str
    title: str
    description: Optional[str]
    url: str

    channel_name: str
    published_at: str

    duration_seconds: int
    captions_available: bool

    view_count: Optional[int]
    like_count: Optional[int]
    comment_count: Optional[int]
    chapters: list[TutorialChapterState]


class VideoTranscriptState(TypedDict):
    video_id: str
    text: str
    full_text: str
    language_code: str
    is_generated: bool
    is_sampled: bool
    original_char_count: int
    sampled_char_count: int


class TutorialTranscriptEvidenceState(TypedDict):
    video_id: str
    video_title: str
    transcript: Optional[
        VideoTranscriptState
    ]


class TutorialQuizEvidenceState(TypedDict):
    resource_type: TutorialResourceType
    resource_id: str
    videos: list[TutorialVideoState]
    transcript_evidence: Optional[
        TutorialTranscriptEvidenceState
    ]


# =====================================================================
# TUTORIAL RESULT
# =====================================================================

class SelectedTutorialState(TypedDict):
    resource_type: TutorialResourceType
    resource_id: str
    title: str
    url: str
    channel_name: str
    selection_reason: str


class TutorialCoverageState(TypedDict):
    summary: str

    covered_concepts: list[str]
    practical_skills: list[str]
    assumed_knowledge: list[str]

    not_covered_node_concepts: list[str]


class TutorialResultState(TypedDict):
    node_id: int
    status: TutorialStatus
    tutorial: Optional[
        SelectedTutorialState
    ]
    coverage: Optional[
        TutorialCoverageState
    ]
    failure_reason: Optional[str]


# =====================================================================
# QUIZ RESULT
# =====================================================================

class QuizOptionState(TypedDict):
    key: QuizOptionKey
    text: str


class QuizQuestionState(TypedDict):
    question_id: str
    question_type: QuizQuestionType
    question: str
    options: list[QuizOptionState]
    correct_option: QuizOptionKey
    correct_answer_explanation: str
    points: int


class QuizResultState(TypedDict):
    node_id: int
    tutorial_resource_id: str
    questions: list[QuizQuestionState]
    max_score: int


# =====================================================================
# PROJECT AGENT RESULT
# =====================================================================

class ProjectResultState(TypedDict):
    node_id: int
    tutorial_resource_id: str

    activity_type: PracticalActivityType

    title: str
    overview: str
    activity_context: str
    learning_objective: str

    tasks: list[str]
    technical_requirements: list[str]
    constraints: list[str]
    deliverables: list[str]
    acceptance_criteria: list[str]

# =====================================================================
# PROJECT ASSESSMENT RESULT
# =====================================================================

class CriterionAssessmentState(TypedDict):
    criterion_id: str
    criterion: str
    status: AssessmentCriterionStatus
    evidence: str
    feedback: str


class BonusCategoryAssessmentState(TypedDict):
    score: int
    evidence: list[str]
    explanation: str


class ProjectAssessmentResultState(TypedDict):
    node_id: int
    tutorial_resource_id: str
    activity_type: PracticalActivityType
    criterion_assessments: list[
        CriterionAssessmentState
    ]
    meaningful_extra_features: (
        BonusCategoryAssessmentState
    )
    creativity_and_innovation: (
        BonusCategoryAssessmentState
    )
    constraint_violations: list[str]
    strengths: list[str]
    improvements: list[str]
    overall_feedback: str

# =====================================================================
# WORKFLOW TRACKING
# =====================================================================

WorkflowStatus = Literal[
    "starting",
    "running_tutorial_agent",
    "running_quiz_agent",
    "running_project_agent",
    "running_project_assessment_agent",
    "ready",
    "failed",
]


# =====================================================================
# LEARNING WORKFLOW STATE
# =====================================================================

class LearningAgentState(
    TypedDict,
    total=False,
):
    # One unique execution of the learning workflow.
    workflow_id: str

    # Roadmap context.
    roadmap_id: int
    roadmap_topic: str

    version_id: int
    version_number: str

    # Current roadmap node being processed.
    node: LearningNode

    # Knowledge the student is expected to already know.
    prerequisite_nodes: list[
        LearningNode
    ]

    # Tutorial Agent final output.
    tutorial_result: TutorialResultState

    # Internal evidence carried from Tutorial Agent
    # to Quiz Agent and Project Agent.
    tutorial_quiz_evidence: (
        TutorialQuizEvidenceState
    )

    # Quiz Agent final output.
    quiz_result: QuizResultState

    # Project Agent final practical activity output.
    project_result: ProjectResultState

    # Project Assessment Agent final output.
    project_assessment_result: (ProjectAssessmentResultState)

    # Workflow tracking.
    status: WorkflowStatus
    error: Optional[str]