from typing import Any
from uuid import uuid4
from graph.postgresSaver_connection import (
    open_checkpointer,
)
from project_assessment_workflow.graph.state import (
    ProjectAssessmentState,
)
from project_assessment_workflow.graph.workflow import (
    build_project_assessment_workflow,
)
# =====================================================================
# THREAD CONFIG
# =====================================================================

def create_thread_config(
    thread_id: str,
) -> dict[str, Any]:

    return {
        "configurable": {
            "thread_id": thread_id,
        }
    }


# =====================================================================
# START PROJECT ASSESSMENT WORKFLOW
# =====================================================================

def start_project_assessment_workflow(
    initial_state: ProjectAssessmentState,
) -> dict[str, Any]:

    thread_id = str(
        uuid4()
    )

    config = create_thread_config(
        thread_id
    )

    complete_initial_state: ProjectAssessmentState = {
        **initial_state,
        "workflow_id": thread_id,
        "status": "starting",
        "error": None,
    }

    with open_checkpointer() as checkpointer:

        workflow = (
            build_project_assessment_workflow()
        )

        graph = workflow.compile(
            checkpointer=checkpointer
        )

        result = graph.invoke(
            complete_initial_state,
            config=config,
        )

    return {
        "thread_id": thread_id,
        "result": result,
    }


# =====================================================================
# GET PROJECT ASSESSMENT WORKFLOW STATE
# =====================================================================

def get_project_assessment_workflow_state(
    thread_id: str,
) -> dict[str, Any]:

    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        workflow = (
            build_project_assessment_workflow()
        )
        graph = workflow.compile(
            checkpointer=checkpointer
        )
        snapshot = graph.get_state(
            config
        )

    if (
        snapshot is None
        or not snapshot.values
    ):
        raise ValueError(
            "Workflow not found."
        )

    return {
        "thread_id": thread_id,
        "state": snapshot.values,
        "next_nodes": list(
            snapshot.next
        ),
        "checkpoint_created_at": (
            snapshot.created_at
        ),
    }