import json
import re
from typing import Any

from agents.llm import llm

from learning_workflow.schema import (
    ProjectAgentResult,
)


# =====================================================================
# JSON EXTRACTION
# =====================================================================

def extract_json_object(
    content: Any,
) -> dict[str, Any]:

    if isinstance(content, dict):
        return content

    if isinstance(content, list):
        text_parts: list[str] = []

        for item in content:

            if isinstance(item, str):
                text_parts.append(
                    item
                )

            elif isinstance(item, dict):

                if "text" in item:
                    text_parts.append(
                        str(item["text"])
                    )

                elif "content" in item:
                    text_parts.append(
                        str(item["content"])
                    )

                else:
                    text_parts.append(
                        json.dumps(
                            item,
                            ensure_ascii=False,
                        )
                    )

            else:
                text_parts.append(
                    str(item)
                )

        text = "\n".join(
            text_parts
        )

    else:
        text = str(content)

    text = text.strip()

    text = re.sub(
        r"^```(?:json)?\s*",
        "",
        text,
        flags=re.IGNORECASE,
    )

    text = re.sub(
        r"\s*```$",
        "",
        text,
    )

    try:
        parsed = json.loads(
            text
        )

        if not isinstance(
            parsed,
            dict,
        ):
            raise ValueError(
                "Project Agent output must "
                "be a JSON object."
            )

        return parsed

    except json.JSONDecodeError:
        pass

    match = re.search(
        r"\{.*\}",
        text,
        re.DOTALL,
    )

    if not match:
        raise ValueError(
            "No valid JSON object was found "
            "in Project Agent response."
        )

    parsed = json.loads(
        match.group(0)
    )

    if not isinstance(
        parsed,
        dict,
    ):
        raise ValueError(
            "Project Agent output must "
            "be a JSON object."
        )

    return parsed


# =====================================================================
# PROJECT EVIDENCE
# =====================================================================

def build_project_evidence(
    agent_input: dict[str, Any],
) -> dict[str, Any]:

    tutorial_result = agent_input[
        "tutorial_result"
    ]

    if (
        tutorial_result.get("status")
        != "ready"
    ):
        raise ValueError(
            "Project Agent requires a ready "
            "Tutorial Agent result."
        )

    tutorial = tutorial_result.get(
        "tutorial"
    )

    if tutorial is None:
        raise ValueError(
            "Ready Tutorial Agent result "
            "must contain a tutorial."
        )

    coverage = tutorial_result.get(
        "coverage"
    )

    if coverage is None:
        raise ValueError(
            "Ready Tutorial Agent result "
            "must contain tutorial coverage."
        )

    tutorial_evidence = agent_input[
        "tutorial_quiz_evidence"
    ]

    if (
        tutorial_evidence[
            "resource_type"
        ]
        != tutorial["resource_type"]
        or tutorial_evidence[
            "resource_id"
        ]
        != tutorial["resource_id"]
    ):
        raise ValueError(
            "Tutorial evidence does not match "
            "the selected tutorial."
        )

    project_evidence = {
        "selected_tutorial": {
            "resource_type": (
                tutorial["resource_type"]
            ),
            "resource_id": (
                tutorial["resource_id"]
            ),
            "title": (
                tutorial["title"]
            ),
            "selection_reason": (
                tutorial["selection_reason"]
            ),
        },

        "tutorial_coverage": {
            "summary": (
                coverage["summary"]
            ),
            "covered_concepts": (
                coverage[
                    "covered_concepts"
                ]
            ),
            "practical_skills": (
                coverage.get(
                    "practical_skills",
                    [],
                )
            ),
            "assumed_knowledge": (
                coverage.get(
                    "assumed_knowledge",
                    [],
                )
            ),
            "not_covered_node_concepts": (
                coverage.get(
                    "not_covered_node_concepts",
                    [],
                )
            ),
        },
    }

    if (
        tutorial["resource_type"]
        == "video"
    ):

        videos = tutorial_evidence[
            "videos"
        ]

        if len(videos) != 1:
            raise ValueError(
                "Standalone tutorial evidence "
                "must contain exactly one video."
            )

        video = videos[0]

        if (
            video["video_id"]
            != tutorial["resource_id"]
        ):
            raise ValueError(
                "Standalone tutorial video ID "
                "does not match the selected "
                "tutorial resource ID."
            )

        project_evidence[
            "tutorial_metadata"
        ] = {
            "resource_type": "video",
            "title": (
                video["title"]
            ),
            "description": (
                video.get(
                    "description"
                )
            ),
            "duration_seconds": (
                video["duration_seconds"]
            ),
            "chapters": (
                video.get(
                    "chapters",
                    [],
                )
            ),
        }

        return project_evidence

    if (
        tutorial["resource_type"]
        == "playlist"
    ):

        videos = tutorial_evidence[
            "videos"
        ]

        if not videos:
            raise ValueError(
                "Selected playlist evidence "
                "contains no usable videos."
            )

        playlist_videos: list[
            dict[str, Any]
        ] = []

        for video in videos:

            playlist_videos.append(
                {
                    "video_id": (
                        video["video_id"]
                    ),
                    "title": (
                        video["title"]
                    ),
                    "description": (
                        video.get(
                            "description"
                        )
                    ),
                    "duration_seconds": (
                        video[
                            "duration_seconds"
                        ]
                    ),
                    "chapters": (
                        video.get(
                            "chapters",
                            [],
                        )
                    ),
                }
            )

        project_evidence[
            "tutorial_metadata"
        ] = {
            "resource_type": "playlist",
            "videos": (
                playlist_videos
            ),
        }

        return project_evidence

    raise ValueError(
        "Unsupported tutorial resource type."
    )


# =====================================================================
# RESULT VALIDATION
# =====================================================================

def validate_project_against_input(
    result: ProjectAgentResult,
    agent_input: dict[str, Any],
) -> None:

    node = agent_input[
        "node"
    ]

    tutorial = agent_input[
        "tutorial_result"
    ].get(
        "tutorial"
    )

    if tutorial is None:
        raise ValueError(
            "Selected tutorial is missing."
        )

    if (
        result.node_id
        != node["id"]
    ):
        raise ValueError(
            "Project Agent returned a node_id "
            "that does not match the requested "
            "roadmap node."
        )

    if (
        result.tutorial_resource_id
        != tutorial["resource_id"]
    ):
        raise ValueError(
            "Project Agent changed the selected "
            "tutorial resource ID."
        )


# =====================================================================
# PROJECT AGENT PROMPT
# =====================================================================

PROJECT_AGENT_SYSTEM_PROMPT = """
You are a Project Agent for a practical Computer Science learning platform.

The platform contains applied Computer Science learning paths such as web,
mobile, AI/ML, databases, cybersecurity, and similar technical areas.

After the student completes one tutorial for a roadmap node, generate the
most appropriate practical activity for that node.

The activity_type must be exactly:

- "project"
- "exercises"


ACTIVITY TYPE

Choose "project" when the node is best practiced through one broader,
coherent practical solution that combines multiple learned concepts or
requirements.

Choose "exercises" when the node is better practiced through focused,
bounded practical tasks that reinforce one foundational skill.

A single final artifact does not automatically make an activity a project.
For example, focused exercises may still produce a diagram, notebook,
query file, configuration, analysis, or other artifact.

Do not choose based only on difficulty.

Apply this reasoning to any Computer Science domain. Examples are
illustrative only.

A single final artifact does not automatically make an activity a project.

If the node is mainly practiced through focused application of one core skill
such as writing queries, drawing models or diagrams, basic programming practice,
configuration practice, command-line practice, or similar bounded tasks, choose
"exercises" even if the student submits one final artifact.

For example, ER modeling activities should normally be classified as
"exercises", not "project", unless the node explicitly requires a broader
database design project beyond focused ER modeling practice.

GROUNDING

Ground the activity in:

- the current roadmap node
- tutorial coverage
- tutorial metadata
- prerequisite nodes

Tutorial coverage is the strongest evidence of what was learned.

Use:

- covered_concepts
- practical_skills
- assumed_knowledge
- not_covered_node_concepts

Do not require major concepts, technologies, techniques, architectures,
framework features, or skills that are not supported by the tutorial
coverage or prerequisites.

Treat not_covered_node_concepts as concepts that must not become required
parts of the activity.

Prerequisite knowledge may be used when necessary, but the activity must
primarily practice the current roadmap node.


INDEPENDENT RESEARCH

The student may independently research small implementation details such
as syntax, documentation, API usage, configuration details, debugging,
or how to obtain a named public dataset.

This must not be used to introduce a major concept that the student has
not learned.


PROJECTS

For activity_type "project", generate one coherent practical assignment.

The student should build, design, configure, analyze, train, implement,
or otherwise produce a meaningful practical result appropriate to the
node.

When a public dataset is needed, you may name a real, well-known dataset
that matches the type of problem taught by the tutorial.

Do not invent datasets.

Do not require advanced techniques that are unsupported by the learning
evidence.

If a dataset is needed, name a real public dataset that the student can search
for and that can be used without requiring unsupported preprocessing.
Do not invent an unnamed or hypothetical dataset.

EXERCISES

For activity_type "exercises", generate several practical tasks around
one skill area or scenario.

Exercises must require practical work, not multiple-choice questions or
simple theoretical recall.

They may involve coding, querying, modeling, configuration, analysis,
design, data manipulation, diagrams, or other practical work appropriate
to the current node.


ACTIVITY CONTEXT

activity_context must contain all concrete information the student needs
to complete the activity.

Depending on the task, this may include:

- a realistic scenario
- business or system requirements
- supplied input conditions
- database or data descriptions
- application requirements
- security or network context
- a named public dataset
- any other necessary task context

The activity must be self-contained.

Do not refer to a "provided scenario", "given data", "supplied system",
or similar information unless that information is actually included in
activity_context.

Provide enough information to solve the activity, but do not provide the
solution.


ACTIVITY DESCRIPTION

The student must clearly understand:

- what they need to do
- what requirements must be satisfied
- which learned concepts must be applied
- what is outside the required scope
- what must be submitted
- how completion will be evaluated


TITLE

Choose a concise, meaningful title.

Avoid generic titles such as "Practice Project", "Exercises",
"Python Practice", or "Database Assignment".


OVERVIEW

Briefly describe the practical activity and its overall purpose.


LEARNING OBJECTIVE

State the important knowledge or skills from the current node that the
activity reinforces.

Do not introduce unsupported learning objectives.


TASKS

tasks must contain concrete work the student must complete.

For projects, tasks represent major required functionality or work.

For exercises, tasks represent the individual practical exercises.

Do not split trivial actions into artificial tasks.


TECHNICAL REQUIREMENTS

technical_requirements must contain the technologies, concepts, methods,
or techniques the student is required to demonstrate.

Only require concepts supported by the tutorial or prerequisites.


CONSTRAINTS

constraints define meaningful boundaries or out-of-scope requirements.

Do not create arbitrary restrictions merely to make the activity harder.


DELIVERABLES

deliverables describe what the student must submit.

Require only artifacts appropriate to the activity.

Do not automatically require deployment, presentations, reports, or
repository links.


ACCEPTANCE CRITERIA

acceptance_criteria must be objective and verifiable.

They must check whether the student satisfied the tasks and technical
requirements without revealing answers the student is expected to derive,
choose, design, calculate, or discover.

Do not use vague criteria.


DO NOT SOLVE THE ACTIVITY

Describe what the student must accomplish, not exactly how to implement it.

Do not provide:

- complete solutions
- complete source code
- complete SQL answers
- completed diagrams
- complete ML implementations
- solution-revealing pseudocode
- exact implementation steps
- unnecessary file names or folder structures
- unnecessary internal architecture decisions

Allow the student to make reasonable implementation decisions.


DIFFICULTY

Use the node difficulty to adjust scope and complexity:

Beginner:
- focused scope
- clear requirements
- straightforward practical work

Intermediate:
- multiple interacting requirements
- meaningful implementation decisions

Advanced:
- deeper design or reasoning
- more realistic complexity and integration

Difficulty must never justify unsupported concepts.

SELF-CHECK

Before producing the JSON, verify that the chosen activity_type matches the
actual nature of the activity.

If the activity mainly reinforces one bounded practical skill through focused
practice, classify it as "exercises".

Do not classify it as "project" merely because it produces a final diagram,
file, notebook, query set, or similar artifact.

OUTPUT

Return ONLY valid JSON.

Use exactly this structure:

{
  "node_id": 1,
  "tutorial_resource_id": "exact_selected_resource_id",
  "activity_type": "project",
  "title": "Activity title",
  "overview": "Clear practical activity description.",
  "activity_context": "Concrete context needed to complete the activity.",
  "learning_objective": "What the activity reinforces.",
  "tasks": [
    "Concrete task the student must complete"
  ],
  "technical_requirements": [
    "Required learned technology, concept, method, or technique"
  ],
  "constraints": [
    "Meaningful boundary or limitation"
  ],
  "deliverables": [
    "Something the student must submit"
  ],
  "acceptance_criteria": [
    "Objective criterion for successful completion"
  ]
}

FINAL GROUNDING RULE

If several alternative methods are taught, require only ONE of them unless
tutorial practical_skills explicitly says the student learned to compare
multiple methods.

Never turn multiple covered alternatives into a comparison task by default.

activity_type must be exactly "project" or "exercises".

Do not add fields outside this structure.

Do not add text before or after the JSON.
"""


# =====================================================================
# PROJECT AGENT RUNNER
# =====================================================================

def run_project_agent(
    agent_input: dict[str, Any],
    regeneration_feedback: str | None = None,
    previous_project_result: dict[str, Any] | None = None,
) -> dict[str, Any]:

    required_fields = {
        "roadmap_topic",
        "node",
        "tutorial_result",
        "tutorial_quiz_evidence",
    }

    missing_fields = (
        required_fields
        - set(agent_input.keys())
    )

    if missing_fields:
        raise ValueError(
            "Project Agent input is missing "
            "required fields: "
            f"{sorted(missing_fields)}"
        )

    tutorial_result = agent_input[
        "tutorial_result"
    ]

    if (
        tutorial_result.get("status")
        != "ready"
    ):
        raise ValueError(
            "Project Agent can only run after "
            "a tutorial has been selected."
        )

    project_evidence = (
        build_project_evidence(
            agent_input
        )
    )

    regeneration_context = ""

    if regeneration_feedback:
        previous_project_text = ""

        if previous_project_result:
            previous_project_text = f"""
    PREVIOUS PRACTICAL ACTIVITY

    {json.dumps(
        previous_project_result,
        indent=2,
        ensure_ascii=False,
    )}
    """

        regeneration_context = f"""
    MANAGER REGENERATION FEEDBACK

    {regeneration_feedback}

    {previous_project_text}

    IMPORTANT:
    Regenerate the practical activity while respecting this feedback
    when it is compatible with the roadmap node and tutorial evidence.

    The feedback must not override tutorial grounding, prerequisites,
    activity-type rules, or the required output structure.
    """

    user_message = f"""
ROADMAP TOPIC

{agent_input["roadmap_topic"]}


ROADMAP NODE

{json.dumps(
    agent_input["node"],
    indent=2,
    ensure_ascii=False,
)}


PREREQUISITE NODES

{json.dumps(
    agent_input.get(
        "prerequisite_nodes",
        [],
    ),
    indent=2,
    ensure_ascii=False,
)}


PRACTICAL ACTIVITY EVIDENCE

{json.dumps(
    project_evidence,
    indent=2,
    ensure_ascii=False,
)}
{regeneration_context}
"""

    messages = [
        {
            "role": "system",
            "content": (
                PROJECT_AGENT_SYSTEM_PROMPT
            ),
        },
        {
            "role": "user",
            "content": (
                user_message
            ),
        },
    ]

    response = llm.invoke(
        messages
    )

    parsed_output = (
        extract_json_object(
            response.content
        )
    )

    validated_result = (
        ProjectAgentResult.model_validate(
            parsed_output
        )
    )

    validate_project_against_input(
        validated_result,
        agent_input,
    )

    return {
        "project_result": (
            validated_result.model_dump()
        ),
    }


# =====================================================================
# LOCAL TEST
# =====================================================================

# =====================================================================
# LOCAL TEST
# =====================================================================

if __name__ == "__main__":

   
    ml_test_input = {
        "roadmap_topic": (
            "Artificial Intelligence "
            "and Machine Learning"
        ),

        "node": {
            "id": 75,
            "title": (
                "Supervised Machine Learning "
                "Classification"
            ),
            "description": (
                "Learn how to train and evaluate "
                "supervised classification models "
                "using labeled data."
            ),
            "difficulty": "intermediate",
        },

        "prerequisite_nodes": [
            {
                "id": 74,
                "title": (
                    "Python for Machine Learning"
                ),
                "description": (
                    "Use Python to load, inspect, "
                    "and manipulate data."
                ),
                "difficulty": "beginner",
            }
        ],

        "tutorial_result": {
            "node_id": 75,
            "status": "ready",

            "tutorial": {
                "resource_type": "video",
                "resource_id": (
                    "TEST_SUPERVISED_ML_VIDEO"
                ),
                "title": (
                    "Supervised Machine Learning "
                    "Classification Tutorial"
                ),
                "url": (
                    "https://www.youtube.com/"
                    "watch?v=TEST_SUPERVISED_ML_VIDEO"
                ),
                "channel_name": (
                    "Test Machine Learning Channel"
                ),
                "selection_reason": (
                    "The tutorial teaches the complete "
                    "basic classification workflow from "
                    "labeled data to model evaluation."
                ),
            },

            "coverage": {
                "summary": (
                    "The tutorial explains supervised "
                    "classification, features and labels, "
                    "training and testing data, model "
                    "training, prediction, and accuracy "
                    "evaluation."
                ),

                "covered_concepts": [
                    "supervised learning",
                    "classification",
                    "features",
                    "target labels",
                    "training data",
                    "testing data",
                    "train-test split",
                    "model training",
                    "prediction",
                    "accuracy",
                    "logistic regression",
                    "K-nearest neighbors",
                ],

                "practical_skills": [
                    "Separating features from the target",
                    "Splitting labeled data into training and testing sets",
                    "Training a classification model",
                    "Generating predictions",
                    "Evaluating predictions using accuracy",
                ],

                "assumed_knowledge": [
                    "Python",
                    "Basic tabular data handling",
                    "Basic numerical concepts",
                ],

                "not_covered_node_concepts": [
                    "cross-validation",
                    "hyperparameter tuning",
                    "feature engineering",
                    "PCA",
                    "ensemble learning",
                    "neural networks",
                ],
            },

            "failure_reason": None,
        },

        "tutorial_quiz_evidence": {
            "resource_type": "video",
            "resource_id": (
                "TEST_SUPERVISED_ML_VIDEO"
            ),

            "videos": [
                {
                    "video_id": (
                        "TEST_SUPERVISED_ML_VIDEO"
                    ),
                    "title": (
                        "Supervised Machine Learning "
                        "Classification Tutorial"
                    ),
                    "description": (
                        "Learn classification using labeled "
                        "data, train-test splitting, model "
                        "training, prediction, logistic "
                        "regression, K-nearest neighbors, "
                        "and accuracy evaluation."
                    ),
                    "url": (
                        "https://www.youtube.com/"
                        "watch?v=TEST_SUPERVISED_ML_VIDEO"
                    ),
                    "channel_name": (
                        "Test Machine Learning Channel"
                    ),
                    "published_at": (
                        "2026-01-01T00:00:00Z"
                    ),
                    "duration_seconds": 2700,
                    "captions_available": True,
                    "view_count": 2500,
                    "like_count": 300,
                    "comment_count": 30,

                    "chapters": [
                        {
                            "start_seconds": 0,
                            "title": (
                                "Supervised Classification"
                            ),
                        },
                        {
                            "start_seconds": 400,
                            "title": (
                                "Features and Labels"
                            ),
                        },
                        {
                            "start_seconds": 800,
                            "title": (
                                "Train Test Split"
                            ),
                        },
                        {
                            "start_seconds": 1200,
                            "title": (
                                "Logistic Regression"
                            ),
                        },
                        {
                            "start_seconds": 1650,
                            "title": (
                                "K Nearest Neighbors"
                            ),
                        },
                        {
                            "start_seconds": 2100,
                            "title": (
                                "Predictions and Accuracy"
                            ),
                        },
                    ],
                }
            ],

            "transcript_evidence": None,
        },
    }

    ml_output = (
        run_project_agent(
            ml_test_input
        )
    )

    print()
    print("=" * 70)
    print("SUPERVISED ML PRACTICAL ACTIVITY TEST")
    print("=" * 70)

    print(
        json.dumps(
            ml_output,
            indent=2,
            ensure_ascii=False,
        )
    )