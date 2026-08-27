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

ExternalResourceType = Literal[
    "official_documentation",
    "github_repository",
    "technical_reference",
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
# EXTERNAL RESOURCES RESULT
# =====================================================================

class ExternalResourceState(TypedDict):
    title: str
    url: str
    resource_type: ExternalResourceType
    source_name: str
    description: str
    why_recommended: str


class ExternalResourcesResultState(TypedDict):
    node_id: int
    resources: list[ExternalResourceState]

# =====================================================================
# LEARNING WORKFLOW TRACKING
# =====================================================================

WorkflowStatus = Literal[
    "starting",
    "running_tutorial_agent",
    "running_learning_agents",
    "awaiting_review",
    "ready",
    "rejected",
    "failed",
]

ReviewStage = Literal[
    "full_review",
]


# =====================================================================
# LEARNING WORKFLOW STATE
# =====================================================================

class LearningAgentState(
    TypedDict,
    total=False,
):
    workflow_id: str

    roadmap_id: int
    roadmap_topic: str

    version_id: int
    version_number: str

    node: LearningNode

    prerequisite_nodes: list[LearningNode]

    tutorial_result: TutorialResultState

    tutorial_quiz_evidence: (
        TutorialQuizEvidenceState
    )

    quiz_result: QuizResultState

    project_result: ProjectResultState

    external_resources_result: ( ExternalResourcesResultState)

    status: WorkflowStatus
    review_stage: (ReviewStage | None)
    rejection_feedback: (str | None)
    # Temporary manager feedback used only for
    # the next regeneration.
    regeneration_feedback: (
        str | None
    )
    error: Optional[str]
