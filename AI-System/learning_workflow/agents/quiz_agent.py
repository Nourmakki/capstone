import json
import re
from typing import Any
from agents.llm import llm
from learning_workflow.schema import (QuizAgentResult,)
from learning_workflow.tools.quiz_transcript import (resolve_standalone_quiz_transcript,)
from learning_workflow.tools.quiz_transcript_summary import (summarize_quiz_transcript,)


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
                "Quiz Agent output must "
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
            "in Quiz Agent response."
        )

    parsed = json.loads(
        match.group(0)
    )

    if not isinstance(
        parsed,
        dict,
    ):
        raise ValueError(
            "Quiz Agent output must "
            "be a JSON object."
        )

    return parsed


# =====================================================================
# QUIZ EVIDENCE
# =====================================================================

def build_quiz_evidence(
    agent_input: dict[str, Any],
) -> dict[str, Any]:

    tutorial_result = agent_input[
        "tutorial_result"
    ]

    if (
        tutorial_result["status"]
        != "ready"
    ):
        raise ValueError(
            "Quiz Agent requires a ready "
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
            "Tutorial quiz evidence does not "
            "match the selected tutorial."
        )

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

        existing_transcript = None

        transcript_evidence = (
            tutorial_evidence.get(
                "transcript_evidence"
            )
        )

        if transcript_evidence:
            transcript = (
                transcript_evidence.get(
                    "transcript"
                )
            )

            if isinstance(
                transcript,
                dict,
            ):
                existing_transcript = (
                    transcript
                )

        resolved_transcript = (
            resolve_standalone_quiz_transcript(
                video["video_id"],
                existing_transcript,
            )
        )

        transcript_summary = None
        transcript_text = None

        if resolved_transcript is not None:

            full_text = (
                resolved_transcript.get(
                    "full_text"
                )
            )

            short_text = (
                resolved_transcript.get(
                    "short_text"
                )
            )

            if (
                isinstance(full_text, str)
                and full_text.strip()
            ):
                transcript_summary = (
                    summarize_quiz_transcript(
                        full_text
                    )
                )

            if transcript_summary is None:

                if (
                    isinstance(short_text, str)
                    and short_text.strip()
                ):
                    transcript_text = (
                        short_text.strip()
                    )

        learning_evidence = None

        if transcript_summary is not None:
            learning_evidence = (
                transcript_summary.get(
                    "learning_evidence"
                )
            )

        return {
            "resource_type": "video",
            "resource_id": (
                tutorial["resource_id"]
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
                video["duration_seconds"]
            ),
            "chapters": (
                video.get(
                    "chapters",
                    [],
                )
            ),
            "transcript_learning_evidence": (
                learning_evidence
            ),
            "transcript": (
                transcript_text
            ),
        }

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

        return {
            "resource_type": "playlist",
            "resource_id": (
                tutorial["resource_id"]
            ),
            "videos": (
                playlist_videos
            ),
        }

    raise ValueError(
        "Unsupported tutorial resource type."
    )



# =====================================================================
# RESULT VALIDATION
# =====================================================================

def validate_quiz_against_input(
    result: QuizAgentResult,
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
            "Quiz Agent returned a node_id "
            "that does not match the "
            "requested roadmap node."
        )

    if (
        result.tutorial_resource_id
        != tutorial["resource_id"]
    ):
        raise ValueError(
            "Quiz Agent changed the selected "
            "tutorial resource ID."
        )

    expected_question_ids = {
        f"q{index}"
        for index in range(
            1,
            11,
        )
    }

    actual_question_ids = {
        question.question_id
        for question
        in result.questions
    }

    if (
        actual_question_ids
        != expected_question_ids
    ):
        raise ValueError(
            "Quiz questions must use IDs "
            "q1 through q10 exactly."
        )

    question_texts = [
        question.question
        .strip()
        .lower()
        for question
        in result.questions
    ]

    if (
        len(set(question_texts))
        != len(question_texts)
    ):
        raise ValueError(
            "Quiz Agent returned duplicate "
            "questions."
        )

    expected_option_order = [
        "A",
        "B",
        "C",
        "D",
    ]

    for question in result.questions:
        option_order = [
            option.key
            for option
            in question.options
        ]

        if (
            option_order
            != expected_option_order
        ):
            raise ValueError(
                "Each quiz question must "
                "order options as "
                "A, B, C, D."
            )

    if result.max_score != 100:
        raise ValueError(
            "Quiz maximum score must be 100."
        )


# =====================================================================
# QUIZ AGENT PROMPT
# =====================================================================

QUIZ_AGENT_SYSTEM_PROMPT = """
You are a Quiz Agent for a Computer Science learning platform.

You generate a quiz after a student completes one selected tutorial for
one roadmap node.

The purpose of the quiz is to test the student's general knowledge and
understanding of the most important concepts taught by that tutorial.

The student's deeper practical implementation ability will be assessed
later through a project. Therefore, this quiz should primarily measure
understanding, reasoning, and knowledge rather than attempting to replace
a practical project assessment.


GROUNDING

Every quiz question must be grounded in the supplied tutorial evidence.

Do not ask about a concept merely because:
- it belongs to the general roadmap topic
- it is commonly associated with the roadmap node
- it appears in prerequisite nodes
- you know it from general Computer Science knowledge

A concept may be tested only when the supplied tutorial evidence gives
reasonable evidence that the tutorial teaches it.

The roadmap node describes the intended learning scope, but it does not
prove that the selected tutorial teaches every concept in that scope.

The supplied tutorial evidence determines what can actually be tested.

CORE CONCEPT PRIORITY

Prioritize concepts that are important for understanding the roadmap
node and that are meaningfully taught by the tutorial.

Do not spend quiz questions on incidental tutorial details, lecturer
opinions, anecdotes, research stories, creator commentary, or facts
whose main purpose is remembering what the tutorial happened to mention.

Avoid questions framed mainly as:
- "According to the tutorial..."
- "Which item was mentioned..."
- "Which item was not listed..."
- "What did the lecturer say..."

unless that information itself represents an important technical concept.

The quiz should test understanding of what was taught, not memory of the
video as a piece of media.


EVIDENCE DEPTH

Question depth must not exceed evidence depth.

Use stronger evidence to support more detailed questions.

Evidence strength should generally be interpreted as follows:

1. Transcript learning evidence
   Strongest evidence when it was extracted from the complete tutorial
   transcript.

2. Transcript text
   Direct transcript evidence that may be sampled and therefore may not
   represent every part of the complete tutorial.

3. Chapter evidence
   Strong evidence that a particular topic or subtopic is present, but
   does not by itself prove every implementation detail.

4. Description evidence
   Good evidence for the general concepts explicitly described.

5. Title evidence
   Useful for broad topic presence only.


STANDALONE VIDEO

A standalone video may include:

- transcript_learning_evidence
- transcript
- title
- description
- chapters

When transcript_learning_evidence is available, it was extracted from
the complete transcript and should be treated as the strongest source
for determining what concepts and details were actually taught.

Use the supplied categories such as:
- important_concepts
- rules_and_relationships
- examples_and_applications
- code_or_commands
- formulas_or_calculations
- caveats_and_limits

Do not assume that every category must contain content.

For example, an empty code_or_commands list means there is not enough
direct evidence to justify a code-analysis question from that category.

An empty formulas_or_calculations list means calculation questions should
not be invented.

If transcript_learning_evidence is null, use transcript evidence when
available.

Transcript evidence may be sampled rather than complete.

Do not assume that absence from a sampled transcript proves that the
complete video never teaches a concept when metadata or chapters provide
reasonable evidence of its presence.

If both transcript_learning_evidence and transcript are null, do NOT
fail the quiz.

Instead, use the video's:
- title
- description
- chapters

Generate more general questions whose depth is justified by that
metadata.

If only broad metadata is available, keep questions broad and
conceptual.

Do not invent detailed syntax, implementation behavior, formulas,
edge cases, or technical details that are not supported by the evidence.


PLAYLIST

For a playlist, evaluate the supplied video metadata collectively.

Use:
- video titles
- descriptions
- durations
- chapters

Descriptions may support general conceptual questions about concepts
they explicitly describe.

Chapter titles provide stronger evidence that a particular subtopic is
meaningfully present.

Do not assume detailed implementation behavior merely from a broad
playlist title or video title.


PREREQUISITES

Prerequisite nodes represent concepts the student may already know.

Do not quiz prerequisite knowledge merely because it appears in the
prerequisite list.

A prerequisite concept may be tested only when the selected tutorial
itself teaches, explains, or meaningfully applies it.


TUTORIAL COVERAGE

The Tutorial Agent coverage result may be used as supporting context.

However, direct tutorial evidence remains the primary grounding source.

Do not ask questions about concepts reported as not covered unless the
direct supplied evidence clearly shows that they are taught.


QUESTION TYPES

Every question must be a single-answer multiple-choice question.

Allowed question_type values are:

- conceptual
- code_analysis
- calculation
- applied_reasoning

Do not force every type into the quiz.

Choose question styles appropriate to the tutorial evidence and roadmap
topic.

Conceptual questions should test important understanding, not trivial
wording.

Code-analysis questions may contain a short code snippet directly inside
the question field.

Use code-analysis only when the supplied tutorial evidence supports that
code concept and behavior.

If question_type is "code_analysis", the question field MUST contain
an actual code snippet for the student to analyze.

If there is no code snippet, do not classify the question as
"code_analysis". Use "conceptual" or "applied_reasoning" instead.

Calculation questions are appropriate only when the tutorial evidence
supports the required formula, rule, or calculation.

Applied-reasoning questions may ask the student to apply a taught concept
to a realistic scenario.


QUIZ QUALITY

Generate exactly 10 questions.

Focus on the most important concepts supported by the tutorial evidence.

When multiple important concepts are taught, distribute questions across
them.

Avoid:
- trivia
- creator or channel facts
- publication dates
- timestamps
- memorizing exact tutorial wording
- duplicate questions
- repeatedly testing the same fact
- unsupported concepts

Each question must have exactly one defensible correct answer.

Each question must contain exactly four distinct and plausible options.

Do not use:
- all of the above
- none of the above


CORRECT ANSWER EXPLANATION

Every question must include a concise explanation of why the correct
answer is correct.

The explanation must remain within the concepts supported by the
tutorial evidence.


SCORING

Generate exactly 10 questions.

Every question is worth exactly 10 points.

The total maximum score is therefore 100.

Do not return max_score because Python computes it.


QUESTION IDS

Use exactly:

q1
q2
q3
q4
q5
q6
q7
q8
q9
q10


OPTIONS

Every question must contain exactly four options in this exact order:

A
B
C
D


OUTPUT

Return ONLY valid JSON.

Use exactly this structure:

{
  "node_id": 1,
  "tutorial_resource_id": "exact_selected_resource_id",
  "questions": [
    {
      "question_id": "q1",
      "question_type": "conceptual",
      "question": "Question text",
      "options": [
        {
          "key": "A",
          "text": "First option"
        },
        {
          "key": "B",
          "text": "Second option"
        },
        {
          "key": "C",
          "text": "Third option"
        },
        {
          "key": "D",
          "text": "Fourth option"
        }
      ],
      "correct_option": "A",
      "correct_answer_explanation": "Why the correct option is correct.",
      "points": 10
    }
  ]
}

Return exactly 10 question objects.

Do not add any text before or after the JSON.
"""


# =====================================================================
# QUIZ AGENT RUNNER
# =====================================================================

def run_quiz_agent(
    agent_input: dict[str, Any],
    regeneration_feedback: str | None = None,
    previous_quiz_result: dict[str, Any] | None = None,
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
            "Quiz Agent input is missing "
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
            "Quiz Agent can only run after "
            "a tutorial has been selected."
        )

    quiz_evidence = build_quiz_evidence(
        agent_input
    )

    regeneration_context = ""

    if regeneration_feedback:
        previous_quiz_text = ""

        if previous_quiz_result:
            previous_quiz_text = f"""
    PREVIOUS QUIZ

    {json.dumps(
        previous_quiz_result,
        indent=2,
        ensure_ascii=False,
    )}
    """

        regeneration_context = f"""
    MANAGER REGENERATION FEEDBACK

    {regeneration_feedback}

    {previous_quiz_text}

    IMPORTANT:
    Regenerate the quiz while respecting this feedback when it is
    compatible with the roadmap node and tutorial evidence.

    The feedback must not override grounding, exactly 10 questions,
    the required output structure, scoring rules, or validation rules.
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


SELECTED TUTORIAL RESULT

{json.dumps(
    tutorial_result,
    indent=2,
    ensure_ascii=False,
)}


TUTORIAL QUIZ EVIDENCE

{json.dumps(
    quiz_evidence,
    indent=2,
    ensure_ascii=False,
)}

{regeneration_context}
"""

    messages = [
        {
            "role": "system",
            "content": (
                QUIZ_AGENT_SYSTEM_PROMPT
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
        QuizAgentResult.model_validate(
            parsed_output
        )
    )

    validate_quiz_against_input(
        validated_result,
        agent_input,
    )

    return {
        "quiz_result": (
            validated_result.model_dump()
        ),
    }


if __name__ == "__main__":

    programming_test_input = {
        "roadmap_topic": "Python Programming",

        "node": {
            "id": 80,
            "title": "Python Loops and Iteration",
            "description": (
                "Learn how Python loops repeat operations, "
                "how range controls iteration, and how break "
                "and continue affect loop execution."
            ),
            "difficulty": "beginner",
        },

        "prerequisite_nodes": [
            {
                "id": 79,
                "title": "Python Basics",
                "description": (
                    "Understand variables, basic data types, "
                    "conditions, and simple Python syntax."
                ),
                "difficulty": "beginner",
            }
        ],

        "tutorial_result": {
            "node_id": 80,
            "status": "ready",

            "tutorial": {
                "resource_type": "video",
                "resource_id": "TEST_PYTHON_LOOPS_VIDEO",
                "title": "Python Loops Tutorial",
                "url": (
                    "https://www.youtube.com/"
                    "watch?v=TEST_PYTHON_LOOPS_VIDEO"
                ),
                "channel_name": "Test Python Channel",
                "selection_reason": (
                    "The video teaches core Python loop behavior "
                    "with several executable examples."
                ),
            },

            "coverage": {
                "summary": (
                    "The tutorial teaches for loops, range, "
                    "break, continue, and repeated execution."
                ),
                "covered_concepts": [
                    "for loops",
                    "range",
                    "break",
                    "continue",
                    "iteration",
                ],
                "practical_skills": [
                    "Writing basic Python loops",
                ],
                "assumed_knowledge": [
                    "Python variables",
                    "basic syntax",
                ],
                "not_covered_node_concepts": [],
            },

            "failure_reason": None,
        },

        "tutorial_quiz_evidence": {
            "resource_type": "video",
            "resource_id": "TEST_PYTHON_LOOPS_VIDEO",

            "videos": [
                {
                    "video_id": (
                        "TEST_PYTHON_LOOPS_VIDEO"
                    ),
                    "title": (
                        "Python Loops Tutorial"
                    ),
                    "description": (
                        "Learn Python for loops, range, "
                        "break, continue, and how loop "
                        "execution changes based on these "
                        "statements."
                    ),
                    "url": (
                        "https://www.youtube.com/"
                        "watch?v=TEST_PYTHON_LOOPS_VIDEO"
                    ),
                    "channel_name": (
                        "Test Python Channel"
                    ),
                    "published_at": (
                        "2026-01-01T00:00:00Z"
                    ),
                    "duration_seconds": 1200,
                    "captions_available": True,
                    "view_count": 1000,
                    "like_count": 100,
                    "comment_count": 10,
                    "chapters": [
                        {
                            "start_seconds": 0,
                            "title": "For loops",
                        },
                        {
                            "start_seconds": 300,
                            "title": "Using range",
                        },
                        {
                            "start_seconds": 600,
                            "title": "Break statement",
                        },
                        {
                            "start_seconds": 850,
                            "title": "Continue statement",
                        },
                    ],
                }
            ],

            "transcript_evidence": {
                "video_id": (
                    "TEST_PYTHON_LOOPS_VIDEO"
                ),
                "video_title": (
                    "Python Loops Tutorial"
                ),
                "transcript": {
                    "video_id": (
                        "TEST_PYTHON_LOOPS_VIDEO"
                    ),
                    "text": (
                        "A for loop repeats a block of code "
                        "for every item in an iterable. "
                        "For example, the code "
                        "'for i in range(3): print(i)' "
                        "prints 0, then 1, then 2. "
                        "The range function does not include "
                        "its stop value. "
                        "For example, range(1, 4) produces "
                        "1, 2, and 3. "
                        "The break statement immediately "
                        "stops the current loop. "
                        "For example, if a loop checks "
                        "'if i == 2: break', no later "
                        "iterations occur after i reaches 2. "
                        "The continue statement skips the "
                        "rest of the current iteration and "
                        "moves to the next iteration. "
                        "For example, if a loop uses "
                        "'if i == 2: continue', the remaining "
                        "statements for i equal to 2 are "
                        "skipped, but the loop continues "
                        "with later values."
                    ),
                    "full_text": (
                        "A for loop repeats a block of code "
                        "for every item in an iterable. "
                        "For example, the code "
                        "'for i in range(3): print(i)' "
                        "prints 0, then 1, then 2. "
                        "The range function does not include "
                        "its stop value. "
                        "For example, range(1, 4) produces "
                        "1, 2, and 3. "
                        "The break statement immediately "
                        "stops the current loop. "
                        "For example, if a loop checks "
                        "'if i == 2: break', no later "
                        "iterations occur after i reaches 2. "
                        "The continue statement skips the "
                        "rest of the current iteration and "
                        "moves to the next iteration. "
                        "For example, if a loop uses "
                        "'if i == 2: continue', the remaining "
                        "statements for i equal to 2 are "
                        "skipped, but the loop continues "
                        "with later values."
                    ),
                    "language_code": "en",
                    "is_generated": False,
                    "is_sampled": False,
                    "original_char_count": 900,
                    "sampled_char_count": 900,
                },
            },
        },
    }

    programming_quiz_output = (
        run_quiz_agent(
            programming_test_input
        )
    )

    print()
    print("=" * 70)
    print("PROGRAMMING QUIZ TEST")
    print("=" * 70)

    print(
        json.dumps(
            programming_quiz_output,
            indent=2,
            ensure_ascii=False,
        )
    )