from typing import Any

from langgraph.graph import (
    END,
    START,
    StateGraph,
)

from langgraph.types import (
    Command,
    interrupt,
)

from learning_workflow.schema import (
    ExternalResourcesResult,
    ProjectAgentResult,
    QuizAgentResult,
)

from learning_workflow.agents.tutorial_agent import (run_tutorial_agent,)
from learning_workflow.agents.quiz_agent import (
    run_quiz_agent,
)

from learning_workflow.agents.project_agent import (
    run_project_agent,
)

from learning_workflow.agents.external_resource_agent import (
    run_external_resources_agent,
)

from .state import LearningAgentState


# =====================================================================
# REVIEW STAGES
# =====================================================================

EDITABLE_STAGES = {
    "quiz",
    "project",
    "external_resources",
}

REGENERATABLE_STAGES = {
    "tutorial",
    "quiz",
    "project",
    "external_resources",
}


# =====================================================================
# HELPERS
# =====================================================================

def require_manager_decision(
    decision: Any,
) -> dict[str, Any]:

    if not isinstance(decision, dict):
        raise ValueError(
            "The manager decision must be an object."
        )

    action = decision.get("action")

    if action not in {
        "approve",
        "edit",
        "regenerate",
        "reject",
    }:
        raise ValueError(
            "Invalid manager decision action."
        )

    if action == "edit":
        stage = decision.get("stage")

        if stage == "tutorial":
            raise ValueError(
                "Tutorial cannot be edited. "
                "Use regenerate instead."
            )

        if stage not in EDITABLE_STAGES:
            raise ValueError(
                "Edit decisions must contain "
                "stage='quiz', 'project', or "
                "'external_resources'."
            )

        if not isinstance(
            decision.get("data"),
            dict,
        ):
            raise ValueError(
                "Edit decisions must contain "
                "a data object."
            )

    if action == "regenerate":
        stage = decision.get("stage")

        if stage not in REGENERATABLE_STAGES:
            raise ValueError(
                "Regenerate decisions must contain "
                "stage='tutorial', 'quiz', 'project', "
                "or 'external_resources'."
            )

        feedback = decision.get(
            "feedback"
        )

        if feedback is not None:
            if not isinstance(
                feedback,
                str,
            ):
                raise ValueError(
                    "Regeneration feedback must "
                    "be a string or null."
                )

            feedback = feedback.strip()

            if len(feedback) > 1000:
                raise ValueError(
                    "Regeneration feedback must not "
                    "exceed 1000 characters."
                )

            decision["feedback"] = (
                feedback or None
            )

    if action == "reject":
        feedback = decision.get("feedback")

        if (
            feedback is not None
            and not isinstance(feedback, str)
        ):
            raise ValueError(
                "Rejection feedback must be "
                "a string or null."
            )

    return decision


def require_edited_result(
    decision: dict[str, Any],
    field_name: str,
) -> dict[str, Any]:

    data = decision.get("data")

    if not isinstance(data, dict):
        raise ValueError(
            "An edit decision must contain "
            "a data object."
        )

    edited_result = data.get(field_name)

    if not isinstance(edited_result, dict):
        raise ValueError(
            f"Edited data must contain a "
            f"'{field_name}' object."
        )

    return edited_result


def get_current_tutorial_resource_id(
    state: LearningAgentState,
) -> str:

    tutorial_result = state.get(
        "tutorial_result"
    )

    if not isinstance(
        tutorial_result,
        dict,
    ):
        raise ValueError(
            "Tutorial result is missing."
        )

    if (
        tutorial_result.get("status")
        != "ready"
    ):
        raise ValueError(
            "Tutorial result is not ready."
        )

    tutorial = tutorial_result.get(
        "tutorial"
    )

    if not isinstance(
        tutorial,
        dict,
    ):
        raise ValueError(
            "Selected tutorial is missing."
        )

    resource_id = tutorial.get(
        "resource_id"
    )

    if not isinstance(
        resource_id,
        str,
    ):
        raise ValueError(
            "Tutorial resource_id is missing."
        )

    return resource_id


def get_tutorial_identity(
    tutorial_result: dict[str, Any],
) -> tuple[str, str]:

    if (
        tutorial_result.get("status")
        != "ready"
    ):
        raise ValueError(
            "Tutorial result must be ready."
        )

    tutorial = tutorial_result.get(
        "tutorial"
    )

    if not isinstance(
        tutorial,
        dict,
    ):
        raise ValueError(
            "Selected tutorial is missing."
        )

    resource_type = tutorial.get(
        "resource_type"
    )

    resource_id = tutorial.get(
        "resource_id"
    )

    if not isinstance(
        resource_type,
        str,
    ):
        raise ValueError(
            "Tutorial resource_type is missing."
        )

    if not isinstance(
        resource_id,
        str,
    ):
        raise ValueError(
            "Tutorial resource_id is missing."
        )

    return (
        resource_type,
        resource_id,
    )


def run_tutorial_once(
    state: LearningAgentState,
) -> tuple[
    dict[str, Any],
    dict[str, Any] | None,
]:

    result = run_tutorial_agent(
        {
            "roadmap_topic": (
                state["roadmap_topic"]
            ),
            "node": state["node"],
            "prerequisite_nodes": state.get(
                "prerequisite_nodes",
                [],
            ),
        },
        regeneration_feedback=state.get(
        "regeneration_feedback"
        ),
        previous_tutorial_result=state.get(
            "tutorial_result"
        ),
    )

    tutorial_result = result.get(
        "tutorial_result"
    )

    if not isinstance(
        tutorial_result,
        dict,
    ):
        raise ValueError(
            "Tutorial Agent must return "
            "tutorial_result."
        )

    if (
        tutorial_result.get("node_id")
        != state["node"]["id"]
    ):
        raise ValueError(
            "Tutorial Agent returned the "
            "wrong node_id."
        )

    status = tutorial_result.get(
        "status"
    )

    if status == "learning_failed":
        return (
            tutorial_result,
            None,
        )

    if status != "ready":
        raise ValueError(
            "Tutorial Agent returned an "
            "invalid status."
        )

    tutorial_quiz_evidence = result.get(
        "tutorial_quiz_evidence"
    )

    if not isinstance(
        tutorial_quiz_evidence,
        dict,
    ):
        raise ValueError(
            "A ready Tutorial Agent result "
            "must contain "
            "tutorial_quiz_evidence."
        )

    return (
        tutorial_result,
        tutorial_quiz_evidence,
    )


# =====================================================================
# EDIT VALIDATION
# =====================================================================

def validate_edited_quiz(
    state: LearningAgentState,
    decision: dict[str, Any],
) -> dict[str, Any]:

    edited_result = require_edited_result(
        decision,
        "quiz_result",
    )

    quiz_data = dict(
        edited_result
    )

    quiz_data.pop(
        "max_score",
        None,
    )

    validated_result = (
        QuizAgentResult.model_validate(
            quiz_data
        )
    )

    current_tutorial_resource_id = (
        get_current_tutorial_resource_id(
            state
        )
    )

    if (
        validated_result.node_id
        != state["node"]["id"]
    ):
        raise ValueError(
            "Edited Quiz must keep the "
            "current node_id."
        )

    if (
        validated_result.tutorial_resource_id
        != current_tutorial_resource_id
    ):
        raise ValueError(
            "Edited Quiz must keep the "
            "current tutorial_resource_id."
        )

    current_quiz = state.get(
        "quiz_result"
    )

    if not isinstance(
        current_quiz,
        dict,
    ):
        raise ValueError(
            "Current quiz_result is missing."
        )

    current_questions = {
        question["question_id"]: question
        for question
        in current_quiz.get(
            "questions",
            [],
        )
    }

    edited_result = (
        validated_result.model_dump()
    )

    for edited_question in edited_result[
        "questions"
    ]:
        question_id = edited_question[
            "question_id"
        ]

        current_question = (
            current_questions.get(
                question_id
            )
        )

        if current_question is None:
            continue

        content_changed = (
            edited_question["question"]
            != current_question["question"]
            or edited_question["options"]
            != current_question["options"]
            or edited_question["correct_option"]
            != current_question[
                "correct_option"
            ]
        )

        explanation_changed = (
            edited_question[
                "correct_answer_explanation"
            ]
            != current_question[
                "correct_answer_explanation"
            ]
        )

        if (
            content_changed
            and not explanation_changed
        ):
            raise ValueError(
                "When a quiz question, its "
                "options, or its correct answer "
                "is edited, "
                "correct_answer_explanation "
                "must also be updated."
            )

    return edited_result

def validate_edited_project(
    state: LearningAgentState,
    edited_result: dict[str, Any],
) -> dict[str, Any]:

    validated_result = (
        ProjectAgentResult.model_validate(
            edited_result
        )
    )

    if (
        validated_result.node_id
        != state["node"]["id"]
    ):
        raise ValueError(
            "Edited project node_id must match "
            "the current roadmap node."
        )

    tutorial_resource_id = (
        get_current_tutorial_resource_id(
            state
        )
    )

    if (
        validated_result.tutorial_resource_id
        != tutorial_resource_id
    ):
        raise ValueError(
            "Edited project must remain linked "
            "to the current tutorial."
        )

    return (
        validated_result.model_dump()
    )


def validate_edited_external_resources(
    state: LearningAgentState,
    edited_result: dict[str, Any],
) -> dict[str, Any]:

    validated_result = (
        ExternalResourcesResult.model_validate(
            edited_result
        )
    )

    if (
        validated_result.node_id
        != state["node"]["id"]
    ):
        raise ValueError(
            "Edited external resources node_id "
            "must match the current roadmap node."
        )

    return (
        validated_result.model_dump()
    )


# =====================================================================
# TUTORIAL AGENT
# =====================================================================

def generate_tutorial(
    state: LearningAgentState,
) -> Command:

    try:
        tutorial_result, tutorial_evidence = (
            run_tutorial_once(
                state
            )
        )

        if (
            tutorial_result["status"]
            == "learning_failed"
        ):
            return Command(
                update={
                    "tutorial_result": (
                        tutorial_result
                    ),
                    "status": "failed",
                    "review_stage": None,
                    "error": (
                        tutorial_result.get(
                            "failure_reason"
                        )
                        or (
                            "No suitable tutorial "
                            "was found."
                        )
                    ),
                },
                goto="failed",
            )

        return Command(
            update={
                "tutorial_result": (
                    tutorial_result
                ),
                "tutorial_quiz_evidence": (
                    tutorial_evidence
                ),
                "status": (
                    "running_learning_agents"
                ),
                "review_stage": None,
                "rejection_feedback": None,
                "error": None,
            },
            goto="prepare_learning_agents",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Tutorial Agent failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# TUTORIAL REGENERATION
# =====================================================================

def regenerate_tutorial(
    state: LearningAgentState,
) -> Command:

    try:
        current_result = state.get(
            "tutorial_result"
        )

        if not isinstance(
            current_result,
            dict,
        ):
            raise ValueError(
                "Current tutorial result "
                "is missing."
            )

        current_identity = (
            get_tutorial_identity(
                current_result
            )
        )

        regenerated_result, regenerated_evidence = (
            run_tutorial_once(
                state
            )
        )

        # No usable new tutorial was found.
        # Keep the complete current workflow unchanged.
        if (
            regenerated_result["status"]
            == "learning_failed"
        ):
            return Command(
                update={
                    "status": "awaiting_review",
                    "review_stage": "full_review",
                    "rejection_feedback": None,
                    "regeneration_feedback": None,
                    "error": None,
                },
                goto="review_all",
            )

        regenerated_identity = (
            get_tutorial_identity(
                regenerated_result
            )
        )

        # The same tutorial was selected again.
        # Nothing downstream needs regeneration.
        if (
            regenerated_identity
            == current_identity
        ):
            return Command(
                update={
                    "status": "awaiting_review",
                    "review_stage": "full_review",
                    "rejection_feedback": None,
                    "regeneration_feedback": None,
                    "error": None,
                },
                goto="review_all",
            )

        # A different tutorial was selected.
        # Only Quiz and Project depend on Tutorial.
        return Command(
            update={
                "tutorial_result": (
                    regenerated_result
                ),
                "tutorial_quiz_evidence": (
                    regenerated_evidence
                ),
                "status": (
                    "running_learning_agents"
                ),
                "review_stage": None,
                "rejection_feedback": None,
                "regeneration_feedback": None,
                "error": None,
            },
            goto="prepare_tutorial_dependents",
        )

    except Exception as error:
        return Command(
            update={
                "status": "failed",
                "review_stage": None,
                "error": (
                    "Tutorial regeneration failed: "
                    f"{error}"
                ),
            },
            goto="failed",
        )


# =====================================================================
# QUIZ AGENT
# =====================================================================

def generate_quiz(
    state: LearningAgentState,
) -> dict[str, Any]:

    result = run_quiz_agent(
        {
            "roadmap_topic": (
                state["roadmap_topic"]
            ),
            "node": state["node"],
            "prerequisite_nodes": state.get(
                "prerequisite_nodes",
                [],
            ),
            "tutorial_result": (
                state["tutorial_result"]
            ),
            "tutorial_quiz_evidence": (
                state[
                    "tutorial_quiz_evidence"
                ]
            ),
        },
        regeneration_feedback=state.get(
            "regeneration_feedback"
        ),
        previous_quiz_result=state.get(
            "quiz_result"
        ),

    )

    quiz_result = result.get(
        "quiz_result"
    )

    if not isinstance(
        quiz_result,
        dict,
    ):
        raise ValueError(
            "Quiz Agent must return "
            "quiz_result."
        )

    return {
        "quiz_result": quiz_result,
    }


# =====================================================================
# PROJECT AGENT
# =====================================================================

def generate_project(
    state: LearningAgentState,
) -> dict[str, Any]:

    result = run_project_agent(
        {
            "roadmap_topic": (
                state["roadmap_topic"]
            ),
            "node": state["node"],
            "prerequisite_nodes": state.get(
                "prerequisite_nodes",
                [],
            ),
            "tutorial_result": (
                state["tutorial_result"]
            ),
            "tutorial_quiz_evidence": (
                state[
                    "tutorial_quiz_evidence"
                ]
            ),
        },

        regeneration_feedback=state.get(
            "regeneration_feedback"
            ),
        previous_project_result=state.get(
            "project_result"
        ),
    )

    project_result = result.get(
        "project_result"
    )

    if not isinstance(
        project_result,
        dict,
    ):
        raise ValueError(
            "Project Agent must return "
            "project_result."
        )

    return {
        "project_result": project_result,
    }


# =====================================================================
# EXTERNAL RESOURCES AGENT
# =====================================================================

def generate_external_resources(
    state: LearningAgentState,
) -> dict[str, Any]:

    result = run_external_resources_agent(
        {
            "node": state["node"],
        },
        regeneration_feedback=state.get(
            "regeneration_feedback"
        ),
        previous_external_resources_result=state.get(
            "external_resources_result"
        ),
    )

    external_resources_result = (
        result.get(
            "external_resources_result"
        )
    )

    if not isinstance(
        external_resources_result,
        dict,
    ):
        raise ValueError(
            "External Resources Agent must return "
            "external_resources_result."
        )

    return {
        "external_resources_result": (
            external_resources_result
        ),
    }


# =====================================================================
# FAN-OUT PREPARATION
# =====================================================================

def prepare_learning_agents(
    state: LearningAgentState,
) -> dict[str, Any]:

    if (
        state.get(
            "tutorial_quiz_evidence"
        )
        is None
    ):
        raise ValueError(
            "Learning agents require "
            "tutorial evidence."
        )

    return {
        "status": "running_learning_agents",
        "review_stage": None,
        "error": None,
    }


def prepare_tutorial_dependents(
    state: LearningAgentState,
) -> dict[str, Any]:

    if (
        state.get(
            "tutorial_quiz_evidence"
        )
        is None
    ):
        raise ValueError(
            "Quiz and Project require "
            "tutorial evidence."
        )

    return {
        "status": "running_learning_agents",
        "review_stage": None,
        "error": None,
    }


# =====================================================================
# MANAGER REVIEW PREPARATION
# =====================================================================

def prepare_manager_review(
    state: LearningAgentState,
) -> dict[str, Any]:

    required_results = {
        "tutorial_result",
        "quiz_result",
        "project_result",
        "external_resources_result",
    }

    missing_results = [
        field_name
        for field_name
        in required_results
        if not isinstance(
            state.get(field_name),
            dict,
        )
    ]

    if missing_results:
        raise ValueError(
            "Learning workflow is missing "
            "required results before review: "
            f"{sorted(missing_results)}"
        )

    return {
        "status": "awaiting_review",
        "review_stage": "full_review",
        "rejection_feedback": None,
        "regeneration_feedback": None,
        "error": None,
    }


# =====================================================================
# MANAGER REVIEW
# =====================================================================

def review_all(
    state: LearningAgentState,
) -> Command:

    decision = interrupt(
        {
            "review_stage": "full_review",
            "instruction": (
                "Review the complete AI learning "
                "material proposal."
            ),
            "tutorial_result": (
                state["tutorial_result"]
            ),
            "quiz_result": (
                state["quiz_result"]
            ),
            "project_result": (
                state["project_result"]
            ),
            "external_resources_result": (
                state[
                    "external_resources_result"
                ]
            ),
            "allowed_actions": {
                "workflow": [
                    "approve",
                    "reject",
                ],
                "tutorial": [
                    "regenerate",
                ],
                "quiz": [
                    "edit",
                    "regenerate",
                ],
                "project": [
                    "edit",
                    "regenerate",
                ],
                "external_resources": [
                    "edit",
                    "regenerate",
                ],
            },
        }
    )

    decision = require_manager_decision(
        decision
    )

    action = decision["action"]

    # -------------------------------------------------
    # REJECT COMPLETE WORKFLOW
    # -------------------------------------------------

    if action == "reject":
        return Command(
            update={
                "status": "rejected",
                "review_stage": None,
                "rejection_feedback": (
                    decision.get(
                        "feedback"
                    )
                ),
                "error": None,
            },
            goto="rejected",
        )

    # -------------------------------------------------
    # APPROVE COMPLETE WORKFLOW
    # -------------------------------------------------

    if action == "approve":
        return Command(
            update={
                "status": "ready",
                "review_stage": None,
                "rejection_feedback": None,
                "error": None,
            },
            goto="complete",
        )

    stage = decision["stage"]

    # -------------------------------------------------
    # REGENERATE
    # -------------------------------------------------

    if action == "regenerate":
        regeneration_feedback = (
            decision.get(
                "feedback"
            )
        )
        if stage == "tutorial":
            return Command(
                update={
                    "status": (
                        "running_tutorial_agent"
                    ),
                    "review_stage": None,
                    "rejection_feedback": None,
                    "regeneration_feedback":(regeneration_feedback),
                    "error": None,
                },
                goto="regenerate_tutorial",
            )

        if stage == "quiz":
            return Command(
                update={
                    "status": (
                        "running_learning_agents"
                    ),
                    "review_stage": None,
                    "rejection_feedback": None,
                    "regeneration_feedback":(regeneration_feedback),
                    "error": None,
                },
                goto="regenerate_quiz",
            )

        if stage == "project":
            return Command(
                update={
                    "status": (
                        "running_learning_agents"
                    ),
                    "review_stage": None,
                    "rejection_feedback": None,
                    "regeneration_feedback":(regeneration_feedback),
                    "error": None,
                },
                goto="regenerate_project",
            )

        return Command(
            update={
                "status": (
                    "running_learning_agents"
                ),
                "review_stage": None,
                "rejection_feedback": None,
                "regeneration_feedback":(regeneration_feedback),
                "error": None,
            },
            goto="regenerate_external_resources",
        )

    # -------------------------------------------------
    # EDIT QUIZ
    # -------------------------------------------------

    if stage == "quiz":
        validated_result = (
            validate_edited_quiz(
                state,
                decision,
            )
        )

        return Command(
            update={
                "quiz_result": (
                    validated_result
                ),
                "status": "awaiting_review",
                "review_stage": "full_review",
                "rejection_feedback": None,
                "error": None,
            },
            goto="review_all",
        )

    # -------------------------------------------------
    # EDIT PROJECT
    # -------------------------------------------------

    if stage == "project":
        edited_result = require_edited_result(
            decision,
            "project_result",
        )

        validated_result = (
            validate_edited_project(
                state,
                edited_result,
            )
        )

        return Command(
            update={
                "project_result": (
                    validated_result
                ),
                "status": "awaiting_review",
                "review_stage": "full_review",
                "rejection_feedback": None,
                "error": None,
            },
            goto="review_all",
        )

    # -------------------------------------------------
    # EDIT EXTERNAL RESOURCES
    # -------------------------------------------------

    edited_result = require_edited_result(
        decision,
        "external_resources_result",
    )

    validated_result = (
        validate_edited_external_resources(
            state,
            edited_result,
        )
    )

    return Command(
        update={
            "external_resources_result": (
                validated_result
            ),
            "status": "awaiting_review",
            "review_stage": "full_review",
            "rejection_feedback": None,
            "error": None,
        },
        goto="review_all",
    )


# =====================================================================
# TERMINAL NODES
# =====================================================================

def complete_workflow(
    state: LearningAgentState,
) -> dict[str, Any]:

    return {
        "status": "ready",
        "review_stage": None,
        "rejection_feedback": None,
        "error": None,
    }


def reject_workflow(
    state: LearningAgentState,
) -> dict[str, Any]:

    return {
        "status": "rejected",
        "review_stage": None,
        "error": None,
    }


def fail_workflow(
    state: LearningAgentState,
) -> dict[str, Any]:

    return {
        "status": "failed",
        "review_stage": None,
    }


# =====================================================================
# BUILD WORKFLOW
# =====================================================================

def build_learning_workflow(
    checkpointer: Any,
):

    builder = StateGraph(
        LearningAgentState
    )

    # Tutorial.
    builder.add_node(
        "generate_tutorial",
        generate_tutorial,
    )

    builder.add_node(
        "regenerate_tutorial",
        regenerate_tutorial,
    )

    # Initial fan-out.
    builder.add_node(
        "prepare_learning_agents",
        prepare_learning_agents,
    )

    builder.add_node(
        "quiz_agent",
        generate_quiz,
    )

    builder.add_node(
        "project_agent",
        generate_project,
    )

    builder.add_node(
        "external_resources_agent",
        generate_external_resources,
    )

    # Tutorial-dependent regeneration.
    builder.add_node(
        "prepare_tutorial_dependents",
        prepare_tutorial_dependents,
    )

    builder.add_node(
        "quiz_after_tutorial",
        generate_quiz,
    )

    builder.add_node(
        "project_after_tutorial",
        generate_project,
    )

    # Individual regeneration.
    builder.add_node(
        "regenerate_quiz",
        generate_quiz,
    )

    builder.add_node(
        "regenerate_project",
        generate_project,
    )

    builder.add_node(
        "regenerate_external_resources",
        generate_external_resources,
    )

    # Review.
    builder.add_node(
        "prepare_manager_review",
        prepare_manager_review,
    )

    builder.add_node(
        "review_all",
        review_all,
    )

    # Terminal nodes.
    builder.add_node(
        "complete",
        complete_workflow,
    )

    builder.add_node(
        "rejected",
        reject_workflow,
    )

    builder.add_node(
        "failed",
        fail_workflow,
    )

    # Start with Tutorial.
    builder.add_edge(
        START,
        "generate_tutorial",
    )

    # Initial generation:
    # Tutorial -> Quiz + Project + External Resources.
    builder.add_edge(
        "prepare_learning_agents",
        "quiz_agent",
    )

    builder.add_edge(
        "prepare_learning_agents",
        "project_agent",
    )

    builder.add_edge(
        "prepare_learning_agents",
        "external_resources_agent",
    )

    builder.add_edge(
        [
            "quiz_agent",
            "project_agent",
            "external_resources_agent",
        ],
        "prepare_manager_review",
    )

    # Different regenerated Tutorial:
    # regenerate only Quiz + Project.
    builder.add_edge(
        "prepare_tutorial_dependents",
        "quiz_after_tutorial",
    )

    builder.add_edge(
        "prepare_tutorial_dependents",
        "project_after_tutorial",
    )

    builder.add_edge(
        [
            "quiz_after_tutorial",
            "project_after_tutorial",
        ],
        "prepare_manager_review",
    )

    # Individual regeneration.
    builder.add_edge(
        "regenerate_quiz",
        "prepare_manager_review",
    )

    builder.add_edge(
        "regenerate_project",
        "prepare_manager_review",
    )

    builder.add_edge(
        "regenerate_external_resources",
        "prepare_manager_review",
    )

    # Review.
    builder.add_edge(
        "prepare_manager_review",
        "review_all",
    )

    # Terminal edges.
    builder.add_edge(
        "complete",
        END,
    )

    builder.add_edge(
        "rejected",
        END,
    )

    builder.add_edge(
        "failed",
        END,
    )

    return builder.compile(
        checkpointer=checkpointer
    )