from typing import Literal, Optional, TypedDict
from learning_workflow.graph.state import (
    PracticalActivityType,
    ProjectResultState,
)
AssessmentCriterionStatus = Literal[
    "met",
    "mostly_met",
    "partially_met",
    "minimally_met",
    "not_met",
    "not_verifiable",
]

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

class AssessmentScoreState(TypedDict):
    base_score: float
    bonus_score: int
    total_score: float
    max_base_score: int
    max_bonus_score: int

ProjectAssessmentWorkflowStatus = Literal[
    "starting",
    "running_project_assessment_agent",
    "ready_for_commit",
    "failed",
]

# =====================================================================
# PROJECT ASSESSMENT WORKFLOW STATE
# =====================================================================

class ProjectAssessmentState(
    TypedDict,
    total=False,
):
    workflow_id: str
    learning_thread_id: str
    project_result: ProjectResultState
    submission_type: Literal["file","github","file and github",]
    submission_file_name: Optional[str]
    submission_file_path: Optional[str]
    repository_url: Optional[str]
    repository_default_branch: Optional[str]
    student_note: Optional[str]
    project_assessment_result: (ProjectAssessmentResultState)
    assessment_score: (AssessmentScoreState)
    status: (
        ProjectAssessmentWorkflowStatus
    )
    error: Optional[str]