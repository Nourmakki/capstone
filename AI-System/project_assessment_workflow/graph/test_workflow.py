from pprint import pprint
from pathlib import Path

from project_assessment_workflow.graph.workflow_options import (
    start_project_assessment_workflow,
    get_project_assessment_workflow_state,
)


if __name__ == "__main__":
    submission_file_path = (
        Path.home()
        / "Downloads"
        / "unsupported_test.xyz"
    )

    initial_state = {
        "learning_thread_id": (
            "6924ff3a-295c-4696-8046-da3408c4f40d"
        ),

        "submission_type": "file",

        "submission_file_name": (
            submission_file_path.name
        ),

        "submission_file_path": str(
            submission_file_path
        ),

        "student_note": (
            "Testing unsupported submission type."
        ),
    }
    print(
        "\nSTARTING PROJECT ASSESSMENT\n"
    )
    workflow_output = (
        start_project_assessment_workflow(
            initial_state
        )
    )
    thread_id = (
        workflow_output[
            "thread_id"
        ]
    )
    result = (
        workflow_output[
            "result"
        ]
    )
    print(
        "\nTHREAD ID\n"
    )

    print(
        thread_id
    )

    print(
        "\nSTATUS\n"
    )

    print(
        result.get(
            "status"
        )
    )

    print(
        "\nERROR\n"
    )

    print(
        result.get(
            "error"
        )
    )

    print(
        "\nASSESSMENT SCORE\n"
    )

    pprint(
        result.get(
            "assessment_score"
        )
    )

    print(
        "\nPROJECT ASSESSMENT RESULT\n"
    )

    pprint(
        result.get(
            "project_assessment_result"
        )
    )

    print(
        "\nRETRIEVING SAVED WORKFLOW STATE\n"
    )

    saved_workflow = (
        get_project_assessment_workflow_state(
            thread_id
        )
    )

    pprint(
        {
            "thread_id": (
                saved_workflow[
                    "thread_id"
                ]
            ),
            "status": (
                saved_workflow[
                    "state"
                ].get(
                    "status"
                )
            ),
            "next_nodes": (
                saved_workflow[
                    "next_nodes"
                ]
            ),
            "checkpoint_created_at": (
                saved_workflow[
                    "checkpoint_created_at"
                ]
            ),
        }
    )