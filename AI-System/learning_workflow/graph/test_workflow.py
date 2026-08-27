import json
from .state import (LearningAgentState,)
from .workflow_options import (get_learning_workflow_state,start_learning_workflow,resume_learning_workflow,)
import copy
from .workflow import (build_learning_workflow,)
from .workflow_options import (create_thread_config,)
from graph.postgresSaver_connection import (open_checkpointer,)
from langgraph.types import Command
# =====================================================================
# TEST STATE
# =========================================
# ============================

TEST_STATE: LearningAgentState = {
    "roadmap_id": 1,
    "roadmap_topic": "Web Development",
    "version_id": 1,
    "version_number": "1.0",

    "node": {
        "id": 101,
        "title": "React State Management",
        "description": (
            "Learn how state works in React and how "
            "to manage component state effectively."
        ),
    },

    "prerequisite_nodes": [
        {
            "id": 100,
            "title": "React Fundamentals",
            "description": (
                "Components, props, JSX, and basic "
                "event handling in React."
            ),
        },
    ],
}


# =====================================================================
# START WORKFLOW TEST
# =====================================================================

def start_test() -> None:

    print("\nStarting Learning Workflow...\n")
    response = start_learning_workflow(TEST_STATE)
    print("START RESPONSE:")
    print(
        json.dumps(
            response,
            indent=2,
            default=str,
            ensure_ascii=False,
        )
    )
    thread_id = response["thread_id"]
    print("\nTHREAD ID:")
    print(thread_id)
    print(
        "\nReading saved PostgreSQL "
        "checkpoint...\n"
    )

    saved_workflow = (
        get_learning_workflow_state(
            thread_id
        )
    )

    print(
        "SAVED WORKFLOW STATE:"
    )

    print(
        json.dumps(
            {
                "thread_id": thread_id,
                "status": saved_state.get(
                    "status"
                ),
                "review_stage": saved_state.get(
                    "review_stage"
                ),
                "tutorial_result": saved_state.get(
                    "tutorial_result"
                ),
                "quiz_result": saved_state.get(
                    "quiz_result"
                ),
                "project_result": saved_state.get(
                    "project_result"
                ),
                "external_resources_result": saved_state.get(
                    "external_resources_result"
                ),
                "next_nodes": saved_workflow.get(
                    "next_nodes"
                ),
            },
            indent=2,
            default=str,
            ensure_ascii=False,
        )
)

    saved_state = saved_workflow["state"]
    status = saved_state.get("status")

    if status != "awaiting_review":
        raise AssertionError(
            "Expected workflow status "
            "'awaiting_review', "
            f"but received '{status}'."
        )

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
        if field_name
        not in saved_state
    ]

    if missing_results:
        raise AssertionError(
            "Workflow reached manager review "
            "without all required results: "
            f"{sorted(missing_results)}"
        )

    print("\nINITIAL WORKFLOW TEST PASSED.")
    print(
        "The workflow generated all learning "
        "materials, paused for manager review, "
        "and was saved in PostgreSQL."
    )

    print(
        "\nSAVE THIS THREAD ID FOR "
        "THE NEXT TEST:"
    )

    print(
        thread_id
    )

THREAD_ID = (
    "27f695a6-97e1-49ce-8710-30be90fc2830"
)

def invalid_quiz_edit_test() -> None:

    saved_workflow = (
        get_learning_workflow_state(
            THREAD_ID
        )
    )

    current_quiz = (
        saved_workflow["state"][
            "quiz_result"
        ]
    )

    edited_quiz = copy.deepcopy(
        current_quiz
    )

    edited_quiz["questions"][0][
        "question"
    ] = (
        "Which React update correctly adds "
        "'Charlie' to the names array?"
    )

    manager_decision = {
        "action": "edit",
        "stage": "quiz",
        "data": {
            "quiz_result": (
                edited_quiz
            )
        },
    }

    resume_learning_workflow(
        THREAD_ID,
        manager_decision,
    )

def repair_review_checkpoint_test() -> None:

    config = create_thread_config(
        THREAD_ID
    )

    with open_checkpointer() as checkpointer:

        graph = build_learning_workflow(
            checkpointer=checkpointer
        )

        repaired_config = graph.update_state(
            config,
            values={
                "status": "awaiting_review",
                "review_stage": "full_review",
                "rejection_feedback": None,
                "regeneration_feedback": None,
                "error": None,
            },
            as_node="prepare_manager_review",
        )

        graph.invoke(
            None,
            config=repaired_config,
        )

    saved_workflow = (
        get_learning_workflow_state(
            THREAD_ID
        )
    )

    state = saved_workflow["state"]

    print(
        json.dumps(
            {
                "status": state.get(
                    "status"
                ),
                "review_stage": state.get(
                    "review_stage"
                ),
                "quiz_question_1": (
                    state["quiz_result"][
                        "questions"
                    ][0]
                ),
                "next_nodes": (
                    saved_workflow.get(
                        "next_nodes"
                    )
                ),
            },
            indent=2,
            ensure_ascii=False,
            default=str,
        )
    )

def edit_external_resources_test() -> None:

    saved_workflow = (
        get_learning_workflow_state(
            THREAD_ID
        )
    )

    current_resources = (
        saved_workflow["state"][
            "external_resources_result"
        ]
    )

    edited_resources = copy.deepcopy(
        current_resources
    )

    edited_resources["resources"][0][
        "description"
    ] = (
        "Edited by manager: official React "
        "documentation for learning how to "
        "structure and manage application state."
    )

    manager_decision = {
        "action": "edit",
        "stage": "external_resources",
        "data": {
            "external_resources_result": (
                edited_resources
            )
        },
    }

    resume_learning_workflow(
        THREAD_ID,
        manager_decision,
    )

    updated_workflow = (
        get_learning_workflow_state(
            THREAD_ID
        )
    )

    state = updated_workflow["state"]

    print(
        json.dumps(
            {
                "status": state.get(
                    "status"
                ),
                "review_stage": state.get(
                    "review_stage"
                ),
                "external_resources_result": (
                    state.get(
                        "external_resources_result"
                    )
                ),
                "next_nodes": (
                    updated_workflow.get(
                        "next_nodes"
                    )
                ),
            },
            indent=2,
            ensure_ascii=False,
            default=str,
        )
    )

def invalid_tutorial_edit_test() -> None:

    saved_workflow = (
        get_learning_workflow_state(
            THREAD_ID
        )
    )

    current_tutorial = (
        saved_workflow["state"][
            "tutorial_result"
        ]
    )

    edited_tutorial = copy.deepcopy(
        current_tutorial
    )

    edited_tutorial["tutorial"][
        "title"
    ] = (
        "Manager Edited Tutorial Title"
    )

    manager_decision = {
        "action": "edit",
        "stage": "tutorial",
        "data": {
            "tutorial_result": (
                edited_tutorial
            )
        },
    }

    resume_learning_workflow(
        THREAD_ID,
        manager_decision,
    )

def approve_workflow_test() -> None:

    manager_decision = {
        "action": "approve",
    }

    resume_learning_workflow(
        THREAD_ID,
        manager_decision,
    )

    updated_workflow = (
        get_learning_workflow_state(
            THREAD_ID
        )
    )

    state = updated_workflow[
        "state"
    ]

    print(
        json.dumps(
            {
                "status": state.get(
                    "status"
                ),
                "review_stage": state.get(
                    "review_stage"
                ),
                "rejection_feedback": (
                    state.get(
                        "rejection_feedback"
                    )
                ),
                "error": state.get(
                    "error"
                ),
                "next_nodes": (
                    updated_workflow.get(
                        "next_nodes"
                    )
                ),
            },
            indent=2,
            ensure_ascii=False,
            default=str,
        )
    )

def reject_after_approval_test() -> None:

    manager_decision = {
        "action": "reject",
        "feedback": (
            "Manager wants to reject "
            "the workflow."
        ),
    }

    resume_learning_workflow(
        THREAD_ID,
        manager_decision,
    )


if __name__ == "__main__":
    reject_after_approval_test()

