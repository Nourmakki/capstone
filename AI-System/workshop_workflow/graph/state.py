from typing import Literal, TypedDict


class Workshop(TypedDict):
    title: str
    organizer: str | None
    speaker: str | None
    date: str
    time: str | None
    location: str
    cost_type: Literal["free","paid","not_specified",]
    price: str | None
    link: str


WorkflowStatus = Literal[
    "starting",
    "running_workshop_agent",
    "awaiting_review",
    "ready_for_commit",
    "rejected",
    "failed",
]


ReviewStage = Literal[
    "workshops",
]


class WorkshopAgentState(TypedDict, total=False):
    # One unique execution of the workflow.
    workflow_id: str

    # Input received from Laravel.
    roadmap_topic: str
    student_location: str | None

    # Current Workshop Agent output.
    workshops: list[Workshop]

    # Manager-approved workshops.
    approved_workshops: list[Workshop]

    # Regeneration information.
    regeneration_feedback: str | None
    generation_attempts: int

    # Workflow tracking.
    status: WorkflowStatus
    review_stage: ReviewStage | None
    rejection_feedback: str | None
    error: str | None