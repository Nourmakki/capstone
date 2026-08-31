import json
from copy import deepcopy

from graph.postgresSaver_connection import (
    open_checkpointer,
)

from .state import RoadmapBuilderState
from .workflow import (
    build_roadmap_builder_workflow,
)
from .workflow_options import (
    create_thread_config,
    get_roadmap_builder_workflow_state,
    resume_roadmap_builder_workflow,
    start_roadmap_builder_workflow,
)


MOBILE_STATE: RoadmapBuilderState = {
    "roadmap_topic": "Mobile Development",
}


# =====================================================================
# PRINT HELPERS
# =====================================================================

def print_review_summary(
    result: dict,
) -> None:
    current_index = result.get(
        "current_roadmap_review_index"
    )

    packages = result.get(
        "roadmap_packages",
        [],
    )

    current_roadmap = None

    if (
        isinstance(
            current_index,
            int,
        )
        and 0
        <= current_index
        < len(packages)
    ):
        current_roadmap = packages[
            current_index
        ][
            "path_title"
        ]

    print(
        json.dumps(
            {
                "status": result.get(
                    "status"
                ),
                "review_stage": result.get(
                    "review_stage"
                ),
                "current_roadmap_review_index": (
                    current_index
                ),
                "current_roadmap": (
                    current_roadmap
                ),
                "generated_roadmaps": [
                    package[
                        "path_title"
                    ]
                    for package in packages
                ],
                "approved_roadmaps": [
                    package[
                        "path_title"
                    ]
                    for package in result.get(
                        "approved_roadmap_packages",
                        [],
                    )
                ],
                "scope_generation_attempts": (
                    result.get(
                        "scope_generation_attempts"
                    )
                ),
                "roadmap_generation_attempts": (
                    result.get(
                        "roadmap_generation_attempts"
                    )
                ),
                "error": result.get(
                    "error"
                ),
            },
            indent=2,
        )
    )


def print_state_summary(
    state: dict,
    next_nodes: list[str] | None = None,
) -> None:
    packages = state.get(
        "roadmap_packages",
        [],
    )

    current_index = state.get(
        "current_roadmap_review_index"
    )

    current_roadmap = None

    if (
        isinstance(
            current_index,
            int,
        )
        and 0
        <= current_index
        < len(packages)
    ):
        current_roadmap = packages[
            current_index
        ][
            "path_title"
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
                "scope_paths": [
                    path[
                        "title"
                    ]
                    for path in state.get(
                        "scope_result",
                        {},
                    ).get(
                        "paths",
                        [],
                    )
                ],
                "generated_roadmaps": [
                    package[
                        "path_title"
                    ]
                    for package in packages
                ],
                "approved_roadmaps": [
                    package[
                        "path_title"
                    ]
                    for package in state.get(
                        "approved_roadmap_packages",
                        [],
                    )
                ],
                "current_roadmap_review_index": (
                    current_index
                ),
                "current_roadmap": (
                    current_roadmap
                ),
                "next_nodes": (
                    next_nodes
                ),
                "error": state.get(
                    "error"
                ),
            },
            indent=2,
        )
    )


# =====================================================================
# START
# =====================================================================

def start_test() -> None:
    response = (
        start_roadmap_builder_workflow(
            MOBILE_STATE
        )
    )

    result = response[
        "result"
    ]

    print(
        json.dumps(
            {
                "thread_id": response[
                    "thread_id"
                ],
                "status": result.get(
                    "status"
                ),
                "review_stage": result.get(
                    "review_stage"
                ),
                "scope_paths": [
                    path[
                        "title"
                    ]
                    for path in result.get(
                        "scope_result",
                        {},
                    ).get(
                        "paths",
                        [],
                    )
                ],
                "error": result.get(
                    "error"
                ),
            },
            indent=2,
        )
    )


# =====================================================================
# READ STATE
# =====================================================================

def get_state_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    print_state_summary(
        state=snapshot[
            "state"
        ],
        next_nodes=snapshot[
            "next_nodes"
        ],
    )


def check_generated_packages_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    state = snapshot[
        "state"
    ]

    packages = state.get(
        "roadmap_packages",
        [],
    )

    print(
        json.dumps(
            {
                "generated_package_count": (
                    len(packages)
                ),
                "generated_packages": [
                    package[
                        "path_title"
                    ]
                    for package in packages
                ],
                "approved_packages": [
                    package[
                        "path_title"
                    ]
                    for package in state.get(
                        "approved_roadmap_packages",
                        [],
                    )
                ],
                "current_roadmap_review_index": (
                    state.get(
                        "current_roadmap_review_index"
                    )
                ),
            },
            indent=2,
        )
    )


# =====================================================================
# SCOPE REVIEW
# =====================================================================

def approve_scope_test(
    thread_id: str,
) -> None:
    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "approve",
            },
        )
    )

    print_review_summary(
        result
    )


def edit_scope_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    state = snapshot[
        "state"
    ]

    edited_scope = deepcopy(
        state[
            "scope_result"
        ]
    )

    edited_scope[
        "scope_summary"
    ] = (
        "Manager-edited scope summary for workflow testing."
    )

    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "edit",
                "stage": "scope",
                "data": (
                    edited_scope
                ),
            },
        )
    )

    print_state_summary(
        result
    )


def regenerate_scope_test(
    thread_id: str,
) -> None:
    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "regenerate",
                "stage": "scope",
                "feedback": (
                    "Keep only the major professional mobile development paths."
                ),
            },
        )
    )

    print_state_summary(
        result
    )


# =====================================================================
# ROADMAP REVIEW
# =====================================================================

def approve_current_roadmap_test(
    thread_id: str,
) -> None:
    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "approve",
            },
        )
    )

    print_review_summary(
        result
    )


def edit_current_roadmap_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    state = snapshot[
        "state"
    ]

    current_index = state[
        "current_roadmap_review_index"
    ]

    current_package = deepcopy(
        state[
            "roadmap_packages"
        ][
            current_index
        ]
    )

    path_title = current_package[
        "path_title"
    ]

    current_package[
        "roadmap_result"
    ][
        "description"
    ] = (
        f"Manager-edited {path_title} roadmap description."
    )

    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "edit",
                "stage": "roadmap",
                "data": (
                    current_package
                ),
            },
        )
    )

    print_review_summary(
        result
    )


def regenerate_current_roadmap_test(
    thread_id: str,
) -> None:
    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "regenerate",
                "stage": "roadmap",
                "feedback": (
                    "Improve the current roadmap where needed while preserving "
                    "its existing correct professional curriculum. "
                    "Do not remove correct competencies unless necessary. "
                    "Strengthen important missing or weak competencies."
                ),
            },
        )
    )

    print_review_summary(
        result
    )


# =====================================================================
# VERIFY EDIT
# =====================================================================

def verify_current_roadmap_edit_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    state = snapshot[
        "state"
    ]

    current_index = state[
        "current_roadmap_review_index"
    ]

    current_package = state[
        "roadmap_packages"
    ][
        current_index
    ]

    print(
        json.dumps(
            {
                "path_title": (
                    current_package[
                        "path_title"
                    ]
                ),
                "description": (
                    current_package[
                        "roadmap_result"
                    ][
                        "description"
                    ]
                ),
            },
            indent=2,
        )
    )


# =====================================================================
# INSPECT CURRENT ROADMAP
# =====================================================================

def inspect_current_roadmap_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    state = snapshot[
        "state"
    ]

    current_index = state[
        "current_roadmap_review_index"
    ]

    current_package = state[
        "roadmap_packages"
    ][
        current_index
    ]

    roadmap = current_package[
        "roadmap_result"
    ]

    print(
        json.dumps(
            {
                "path_title": (
                    current_package[
                        "path_title"
                    ]
                ),
                "description": (
                    roadmap[
                        "description"
                    ]
                ),
                "nodes": [
                    {
                        "node_id": node[
                            "node_id"
                        ],
                        "title": node[
                            "title"
                        ],
                    }
                    for node in roadmap[
                        "nodes"
                    ]
                ],
                "freshness_summary": (
                    roadmap[
                        "freshness_summary"
                    ]
                ),
                "child_roadmaps": [
                    child[
                        "choice_title"
                    ]
                    for child in current_package.get(
                        "child_roadmaps",
                        [],
                    )
                ],
            },
            indent=2,
        )
    )


# =====================================================================
# VERIFY APPROVED PACKAGES
# =====================================================================

def verify_approved_roadmaps_untouched_test(
    thread_id: str,
) -> None:
    snapshot = (
        get_roadmap_builder_workflow_state(
            thread_id
        )
    )

    state = snapshot[
        "state"
    ]

    roadmap_packages = state[
        "roadmap_packages"
    ]

    approved_packages = state[
        "approved_roadmap_packages"
    ]

    results = {}

    for approved_package in approved_packages:
        path_id = approved_package[
            "path_id"
        ]

        working_package = next(
            package
            for package in roadmap_packages
            if (
                package[
                    "path_id"
                ]
                == path_id
            )
        )

        results[
            approved_package[
                "path_title"
            ]
        ] = {
            "unchanged": (
                approved_package
                == working_package
            ),
            "description": (
                working_package[
                    "roadmap_result"
                ][
                    "description"
                ]
            ),
        }

    print(
        json.dumps(
            results,
            indent=2,
        )
    )


# =====================================================================
# REJECT
# =====================================================================

def reject_test(
    thread_id: str,
) -> None:
    result = (
        resume_roadmap_builder_workflow(
            thread_id=thread_id,
            manager_decision={
                "action": "reject",
                "feedback": (
                    "The workflow is not suitable for approval."
                ),
            },
        )
    )

    print_review_summary(
        result
    )


# =====================================================================
# RECOVER INTERRUPTED GENERATION
# =====================================================================

def continue_test(
    thread_id: str,
) -> None:
    config = create_thread_config(
        thread_id
    )

    with open_checkpointer() as checkpointer:
        graph = (
            build_roadmap_builder_workflow(
                checkpointer=checkpointer
            )
        )

        snapshot = graph.get_state(
            config
        )

        if not snapshot.values:
            raise ValueError(
                "Workflow not found."
            )

        status = snapshot.values.get(
            "status"
        )

        if status == "awaiting_review":
            raise ValueError(
                "Workflow is awaiting manager review. "
                "Use a manager decision instead of continue_test()."
            )

        print(
            json.dumps(
                {
                    "current_status": (
                        status
                    ),
                    "pending_nodes": list(
                        snapshot.next
                    ),
                },
                indent=2,
            )
        )

        result = graph.invoke(
            None,
            config=config,
        )

    print_review_summary(
        result
    )


if __name__ == "__main__":
 if __name__ == "__main__":
    verify_approved_roadmaps_untouched_test(
        "615a7a69-c436-43bd-b486-1f06137a97e9"
    )