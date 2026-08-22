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

PositiveNodeId = Annotated[
    int,
    Field(gt=0),
]

Difficulty = Literal[
    "beginner",
    "intermediate",
    "advanced",
]


# =====================================================================
# TUTORIAL AGENT INPUT
# =====================================================================

class LearningNode(BaseModel):
    """
    One roadmap node for which learning material
    may be generated.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    id: PositiveNodeId
    title: NonEmptyString
    description: NonEmptyString | None = None
    difficulty: Difficulty | None = None


class TutorialAgentInput(BaseModel):
    """
    Information the Tutorial Agent receives
    when finding a tutorial for one roadmap node.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    roadmap_topic: NonEmptyString

    node: LearningNode

    prerequisite_nodes: list[LearningNode] = Field(
        default_factory=list
    )

# =====================================================================
# SELECTED YOUTUBE TUTORIAL
# =====================================================================
TutorialResourceType = Literal["video","playlist",]

class TutorialChapter(BaseModel):
    model_config = ConfigDict(
        extra="forbid"
    )

    start_seconds: int = Field( ge=0)
    title: NonEmptyString

class TutorialVideo(BaseModel):
    """
    One YouTube video that belongs to the selected tutorial.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    video_id: NonEmptyString
    title: NonEmptyString
    description: str | None = None
    url: NonEmptyString
    channel_name: NonEmptyString
    published_at: NonEmptyString
    duration_seconds: int = Field(gt=0)
    captions_available: bool

    view_count: int | None = Field(
        default=None,
        ge=0,
    )

    like_count: int | None = Field(
        default=None,
        ge=0,
    )

    comment_count: int | None = Field(
        default=None,
        ge=0,
    )

    chapters: list[
        TutorialChapter
    ] = Field(
        default_factory=list
    )
    


class SelectedTutorial(BaseModel):
    """
    The final YouTube learning resource selected
    for a roadmap node.

    It may be one video or an ordered playlist.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    resource_type: TutorialResourceType
    resource_id: NonEmptyString
    title: NonEmptyString
    url: NonEmptyString
    channel_name: NonEmptyString
    selection_reason: NonEmptyString

class TutorialCoverage(BaseModel):
    """
    Educational coverage of the selected tutorial.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    summary: NonEmptyString
    covered_concepts: list[NonEmptyString]
    practical_skills: list[NonEmptyString] = Field(
        default_factory=list
    )
    assumed_knowledge: list[NonEmptyString] = Field(
        default_factory=list
    )
    not_covered_node_concepts: list[
        NonEmptyString
    ] = Field(
        default_factory=list
    )

    @model_validator(mode="after")
    def validate_coverage(
        self,
    ) -> "TutorialCoverage":

        if not self.covered_concepts:
            raise ValueError(
                "Tutorial coverage must contain "
                "at least one covered concept."
            )

        return self


# =====================================================================
# TUTORIAL AGENT FINAL RESULT
# =====================================================================

TutorialStatus = Literal[
    "ready",
    "learning_failed",
]


class TutorialAgentResult(BaseModel):
    """
    Final result for one roadmap node.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    node_id: PositiveNodeId
    status: TutorialStatus
    tutorial: SelectedTutorial | None = None
    coverage: TutorialCoverage | None = None
    failure_reason: NonEmptyString | None = None

    @model_validator(mode="after")
    def validate_result(
        self,
    ) -> "TutorialAgentResult":

        if self.status == "ready":
            if self.tutorial is None:
                raise ValueError(
                    "A ready tutorial result must "
                    "contain a selected tutorial."
                )

            if self.coverage is None:
                raise ValueError(
                    "A ready tutorial result must "
                    "contain tutorial coverage."
                )

            if self.failure_reason is not None:
                raise ValueError(
                    "A ready tutorial result must not "
                    "contain a failure reason."
                )

        if self.status == "learning_failed":
            if self.tutorial is not None:
                raise ValueError(
                    "A failed tutorial result must not "
                    "contain a selected tutorial."
                )

            if self.coverage is not None:
                raise ValueError(
                    "A failed tutorial result must not "
                    "contain tutorial coverage."
                )

            if self.failure_reason is None:
                raise ValueError(
                    "A failed tutorial result must "
                    "contain a failure reason."
                )

        return self