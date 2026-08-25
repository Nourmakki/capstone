from learning_workflow.graph.state import (ProjectAssessmentResultState,)

STATUS_SCORE_VALUES = {
    "met": 1.0,
    "mostly_met": 0.75,
    "partially_met": 0.5,
    "minimally_met": 0.25,
    "not_met": 0.0,
    "not_verifiable": 0.0,
}


def calculate_assessment_score(
    assessment_result: ProjectAssessmentResultState,
) -> dict[str, float | int]:
    criterion_assessments = assessment_result[
        "criterion_assessments"
    ]

    if not criterion_assessments:
        raise ValueError(
            "Cannot calculate assessment score "
            "without criterion assessments."
        )

    total_criterion_value = sum(
        STATUS_SCORE_VALUES[
            assessment["status"]
        ]
        for assessment in criterion_assessments
    )

    base_score = (
        total_criterion_value
        / len(criterion_assessments)
    ) * 100

    meaningful_extra_score = assessment_result[
        "meaningful_extra_features"
    ]["score"]

    creativity_score = assessment_result[
        "creativity_and_innovation"
    ]["score"]

    bonus_score = (
        meaningful_extra_score
        + creativity_score
    )

    total_score = (
        base_score
        + bonus_score
    )

    return {
        "base_score": round(
            base_score,
            2,
        ),
        "bonus_score": bonus_score,
        "total_score": round(
            total_score,
            2,
        ),
        "max_base_score": 100,
        "max_bonus_score": 10,
    }