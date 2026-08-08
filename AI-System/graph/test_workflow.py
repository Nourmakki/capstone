import json

from .workflow_options import (
    get_roadmap_workflow_state,
    resume_roadmap_workflow,
    start_roadmap_workflow,
)
from .state import AgentState


DEVOPS_STATE: AgentState = {
    "roadmap_id": 1,
    "roadmap_title": "DevOps Engineering",
    "roadmap_topic": "devops engineering",
    "version_id": 1,
    "version_number": "1.0",
    "nodes": [
        {
            "id": 1,
            "title": "Linux",
            "description": (
                "Linux command-line usage and "
                "system administration."
            ),
        },
        {
            "id": 2,
            "title": "Git",
            "description": (
                "Version control, branching, and merging."
            ),
        },
        {
            "id": 3,
            "title": "CI/CD",
            "description": (
                "Automating builds, tests, delivery, "
                "and deployment."
            ),
        },
    ],
    "edges": [
        {
            "from_node_id": 1,
            "to_node_id": 2,
        },
        {
            "from_node_id": 2,
            "to_node_id": 3,
        },
    ],
}


def start_test() -> None:
    response = start_roadmap_workflow(
        DEVOPS_STATE
    )

    print(
        json.dumps(
            response,
            indent=2,
            default=str,
        )
    )

def resume_test() -> None:
    thread_id = "07c786f3-fc48-41d0-af54-b81288d2bdbe"

    result = resume_roadmap_workflow(
        thread_id=thread_id,
        admin_decision={
            "action": "approve",
        },
    )

    print(
        json.dumps(
            result,
            indent=2,
            default=str,
        )
    )


if __name__ == "__main__":
   resume_test()