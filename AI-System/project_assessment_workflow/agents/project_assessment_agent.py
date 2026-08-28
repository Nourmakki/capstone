import json
import re
from typing import Any
from agents.llm import llm
from project_assessment_workflow.schema import (ProjectAssessmentResult,)
from project_assessment_workflow.tools.visual_evidence import ( process_visual_evidence,)
from learning_workflow.graph.state import (ProjectResultState,)
from project_assessment_workflow.graph.state import (
    ProjectAssessmentResultState,
)

STATUS_SCORE_VALUES = {
    "met": 1.0,
    "mostly_met": 0.75,
    "partially_met": 0.5,
    "minimally_met": 0.25,
    "not_met": 0.0,
    "not_verifiable": 0.0,
}


MAX_VISUAL_ITEMS_FOR_ASSESSMENT = 10


# =====================================================================
# JSON EXTRACTION
# =====================================================================

def extract_json_object(
    content: Any,
) -> dict[str, Any]:

    if isinstance(
        content,
        dict,
    ):
        return content

    if isinstance(
        content,
        list,
    ):
        text_parts: list[str] = []

        for item in content:

            if isinstance(
                item,
                str,
            ):
                text_parts.append(
                    item
                )

            elif isinstance(
                item,
                dict,
            ):

                if "text" in item:
                    text_parts.append(
                        str(
                            item["text"]
                        )
                    )

                elif "content" in item:
                    text_parts.append(
                        str(
                            item["content"]
                        )
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
                    str(
                        item
                    )
                )

        text = "\n".join(
            text_parts
        )

    else:
        text = str(
            content
        )

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
                "Project Assessment Agent "
                "output must be a JSON object."
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
            "in Project Assessment Agent "
            "response."
        )

    parsed = json.loads(
        match.group(0)
    )

    if not isinstance(
        parsed,
        dict,
    ):
        raise ValueError(
            "Project Assessment Agent "
            "output must be a JSON object."
        )

    return parsed


# =====================================================================
# ACTIVITY EVIDENCE
# =====================================================================

def build_activity_evidence(
    project_result: ProjectResultState,
) -> dict[str, Any]:

    acceptance_criteria = (
        project_result.get(
            "acceptance_criteria",
            [],
        )
    )

    if not acceptance_criteria:
        raise ValueError(
            "Project Assessment Agent "
            "requires acceptance criteria."
        )

    numbered_criteria = [
        {
            "criterion_id": (
                f"c{index}"
            ),
            "criterion": (
                criterion
            ),
        }
        for index, criterion
        in enumerate(
            acceptance_criteria,
            start=1,
        )
    ]

    return {
        "node_id": (
            project_result[
                "node_id"
            ]
        ),
        "tutorial_resource_id": (
            project_result[
                "tutorial_resource_id"
            ]
        ),
        "activity_type": (
            project_result[
                "activity_type"
            ]
        ),
        "title": (
            project_result[
                "title"
            ]
        ),
        "overview": (
            project_result[
                "overview"
            ]
        ),
        "activity_context": (
            project_result[
                "activity_context"
            ]
        ),
        "learning_objective": (
            project_result[
                "learning_objective"
            ]
        ),
        "tasks": (
            project_result[
                "tasks"
            ]
        ),
        "technical_requirements": (
            project_result[
                "technical_requirements"
            ]
        ),
        "constraints": (
            project_result[
                "constraints"
            ]
        ),
        "deliverables": (
            project_result[
                "deliverables"
            ]
        ),
        "acceptance_criteria": (
            numbered_criteria
        ),
    }


# =====================================================================
# SUBMISSION TEXT EVIDENCE
# =====================================================================

def build_submission_text_evidence(
    submission_evidence: dict[str, Any],
) -> dict[str, Any]:

    text_files = (
        submission_evidence.get(
            "text_files",
            [],
        )
    )

    student_note = (
        submission_evidence.get(
            "student_note"
        )
    )

    processing_notes = (
        submission_evidence.get(
            "processing_notes",
            [],
        )
    )

    return {
        "submission_file_name": (
            submission_evidence.get(
                "submission_file_name"
            )
        ),
        "submission_extension": (
            submission_evidence.get(
                "submission_extension"
            )
        ),
        "student_note": (
            student_note
        ),
        "text_files": (
            text_files
        ),
        "processing_notes": (
            processing_notes
        ),
    }


# =====================================================================
# MULTIMODAL USER CONTENT
# =====================================================================

def build_assessment_user_content(
    activity_evidence: dict[str, Any],
    submission_evidence: dict[str, Any],
) -> list[dict[str, Any]]:

    submission_text_evidence = (
        build_submission_text_evidence(
            submission_evidence
        )
    )

    visual_files = (
        submission_evidence.get(
            "visual_files",
            [],
        )
    )

    processed_visuals = (
        process_visual_evidence(
            visual_files
        )
    )

    processed_visuals = (
        processed_visuals[
            :MAX_VISUAL_ITEMS_FOR_ASSESSMENT
        ]
    )

    text_message = f"""
PRACTICAL ACTIVITY TO ASSESS

{json.dumps(
    activity_evidence,
    indent=2,
    ensure_ascii=False,
)}


STUDENT SUBMISSION TEXT EVIDENCE

{json.dumps(
    submission_text_evidence,
    indent=2,
    ensure_ascii=False,
)}


IMPORTANT

The student_note is only a navigation hint or claim from the student.
It is not proof that a feature or requirement is implemented.

Assess claims using the actual submitted evidence.
"""

    user_content: list[
        dict[str, Any]
    ] = [
        {
            "type": "text",
            "text": text_message,
        }
    ]

    for visual in processed_visuals:

        visual_label = (
            "VISUAL SUBMISSION EVIDENCE\n"
            f"File: {visual['file_name']}\n"
        )

        if (
            visual.get(
                "page_number"
            )
            is not None
        ):
            visual_label += (
                "Page: "
                f"{visual['page_number']}\n"
            )

        user_content.append(
            {
                "type": "text",
                "text": visual_label,
            }
        )

        user_content.append(
            {
                "type": "image_url",
                "image_url": {
                    "url": (
                        "data:"
                        f"{visual['media_type']}"
                        ";base64,"
                        f"{visual['base64_data']}"
                    )
                },
            }
        )

    return user_content


# =====================================================================
# RESULT VALIDATION
# =====================================================================

def validate_assessment_against_input(
    result: ProjectAssessmentResult,
    project_result: ProjectResultState,
) -> None:

    if (
        result.node_id
        != project_result[
            "node_id"
        ]
    ):
        raise ValueError(
            "Project Assessment Agent "
            "changed the node_id."
        )

    if (
        result.tutorial_resource_id
        != project_result[
            "tutorial_resource_id"
        ]
    ):
        raise ValueError(
            "Project Assessment Agent "
            "changed the tutorial_resource_id."
        )

    if (
        result.activity_type
        != project_result[
            "activity_type"
        ]
    ):
        raise ValueError(
            "Project Assessment Agent "
            "changed the activity_type."
        )

    expected_criteria = [
        (
            f"c{index}",
            criterion,
        )
        for index, criterion
        in enumerate(
            project_result[
                "acceptance_criteria"
            ],
            start=1,
        )
    ]

    actual_criteria = [
        (
            assessment.criterion_id,
            assessment.criterion,
        )
        for assessment
        in result.criterion_assessments
    ]

    if (
        actual_criteria
        != expected_criteria
    ):
        raise ValueError(
            "Project Assessment Agent must "
            "assess every original acceptance "
            "criterion exactly once, in the "
            "original order, without changing "
            "the criterion text."
        )

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


# =====================================================================
# PROJECT ASSESSMENT AGENT PROMPT
# =====================================================================

PROJECT_ASSESSMENT_SYSTEM_PROMPT = """
You are a Project Assessment Agent for a Computer Science learning
platform.

You assess a student's practical activity submission.

The activity may be either:
- a project
- practical exercises

Your assessment must be based only on:
1. the supplied practical activity specification
2. the actual student submission evidence

Do not judge the student against requirements that were never assigned.


BASE ASSESSMENT

The acceptance criteria are the grading rubric.

Assess every supplied acceptance criterion exactly once.

Preserve:
- criterion_id
- criterion text
- original criterion order

For each criterion choose exactly one status:

- "met": the criterion is fully satisfied by the submission evidence.
- "mostly_met": the criterion is substantially satisfied, but a relatively small required part is incomplete or incorrect.
- "partially_met": a meaningful portion of the criterion is satisfied, but substantial required work is still incomplete or incorrect.
- "minimally_met": only a small portion of the criterion is satisfied; most of the required work is missing or incorrect.
- "not_met": the evidence shows that the criterion is not satisfied.
- "not_verifiable": the submission does not provide enough evidence to determine whether the criterion is satisfied.

Do not use partially_met merely because you are uncertain.
Use not_verifiable when evidence is insufficient.


EVIDENCE

Base every judgment on concrete submission evidence.

Evidence may include:
- source code
- SQL
- notebook code and outputs
- displayed DataFrames
- dataset summaries
- configuration files
- documentation
- diagrams
- screenshots
- PDF pages
- notebook-generated graphs

Do not assume functionality exists merely because:
- the student claims it exists
- a filename suggests it exists
- a README says it exists without supporting implementation evidence

A student_note is only a navigation hint and is not proof.

Do not invent runtime behavior that cannot be verified from the supplied
evidence.

When source code clearly demonstrates a behavior or requirement, it may
be used as evidence even if the application was not executed by you.


TASKS, REQUIREMENTS, CONSTRAINTS, AND DELIVERABLES

Use the activity's tasks, technical requirements, constraints, and
deliverables to correctly interpret the acceptance criteria.

Do not score these categories separately.

Do not create arbitrary penalties.

If a constraint violation materially causes an acceptance criterion to
be unsatisfied, reflect that in the criterion assessment and also record
the violation in constraint_violations.

Do not penalize:
- different valid implementation choices
- formatting or style not required by the activity
- missing optional features
- technologies or architecture choices that were not restricted
- extra work that does not damage the required solution


BONUS

Assess two bonus categories independently.

meaningful_extra_features:
0 to 5 points.

Award points only for relevant, working or clearly demonstrated additions
that meaningfully improve the activity beyond its required scope.

Do not award bonus merely for:
- required work
- extra files
- unnecessary complexity
- cosmetic changes with little value
- claims without evidence

creativity_and_innovation:
0 to 5 points.

Award points for thoughtful originality in the student's solution,
design, analysis, interaction, presentation, or technical approach when
the evidence demonstrates genuine value beyond ordinary completion.

A valid alternative implementation is not automatically innovative.

Bonus must never change whether the required acceptance criteria were
met.

A student may have incomplete required work and still receive a small
bonus for genuine extra work, but bonus does not replace missing base
requirements.


FEEDBACK

Evidence must identify the concrete basis for the status or bonus.

Feedback should be concise, useful, and specific.

Strengths should describe what the student did well.

Improvements should focus on meaningful ways the submission could better
satisfy the assigned activity.

Do not reveal hidden reasoning or provide irrelevant commentary.


OUTPUT

Return ONLY valid JSON.

Use exactly this structure:

{
  "node_id": 1,
  "tutorial_resource_id": "resource_id",
  "activity_type": "project",
  "criterion_assessments": [
    {
      "criterion_id": "c1",
      "criterion": "Exact original acceptance criterion text.",
      "status": "met",
      "evidence": "Concrete evidence from the submission.",
      "feedback": "Concise criterion-specific feedback."
    }
  ],
  "meaningful_extra_features": {
    "score": 0,
    "evidence": [],
    "explanation": "Explanation of the bonus judgment."
  },
  "creativity_and_innovation": {
    "score": 0,
    "evidence": [],
    "explanation": "Explanation of the bonus judgment."
  },
  "constraint_violations": [],
  "strengths": [],
  "improvements": [],
  "overall_feedback": "Concise overall assessment."
}

Do not return base_score, bonus_score, or total_score.
Those values are calculated deterministically in Python after the
assessment output is validated.
"""


# =====================================================================
# PROJECT ASSESSMENT AGENT RUNNER
# =====================================================================

def run_project_assessment_agent(
    agent_input: dict[str, Any],
) -> dict[str, Any]:

    required_fields = {
        "project_result",
        "submission_evidence",
    }

    missing_fields = (
        required_fields
        - set(
            agent_input.keys()
        )
    )

    if missing_fields:
        raise ValueError(
            "Project Assessment Agent input "
            "is missing required fields: "
            f"{sorted(missing_fields)}"
        )

    project_result = (
        agent_input[
            "project_result"
        ]
    )

    submission_evidence = (
        agent_input[
            "submission_evidence"
        ]
    )

    activity_evidence = (
        build_activity_evidence(
            project_result
        )
    )

    text_files = (
        submission_evidence.get(
            "text_files",
            [],
        )
    )

    visual_files = (
        submission_evidence.get(
            "visual_files",
            [],
        )
    )

    if (
        not text_files
        and not visual_files
    ):
        raise ValueError(
            "Project Assessment Agent "
            "received no usable submission "
            "evidence."
        )

    user_content = (
        build_assessment_user_content(
            activity_evidence,
            submission_evidence,
        )
    )

    messages = [
        {
            "role": "system",
            "content": (
                PROJECT_ASSESSMENT_SYSTEM_PROMPT
            ),
        },
        {
            "role": "user",
            "content": (
                user_content
            ),
        },
    ]

    response = llm.invoke(
        messages
    )

    parsed_output = (
        extract_json_object(
            response.text
        )
    )

    parsed_output[
        "node_id"
    ] = project_result[
        "node_id"
    ]

    parsed_output[
        "tutorial_resource_id"
    ] = project_result[
        "tutorial_resource_id"
    ]

    parsed_output[
        "activity_type"
    ] = project_result[
        "activity_type"
    ]

    validated_result = (
        ProjectAssessmentResult.model_validate(
            parsed_output
        )
    )

    validate_assessment_against_input(
        validated_result,
        project_result,
    )

    assessment_result = (
        validated_result.model_dump()
    )

    assessment_score = (
        calculate_assessment_score(
            assessment_result
        )
    )

    return {
        "project_assessment_result": (
            assessment_result
        ),
        "assessment_score": (
            assessment_score
        ),
    }

if __name__ == "__main__":
    import io
    from pprint import pprint
    from PIL import Image, ImageDraw

    image = Image.new(
        "RGB",
        (1200, 700),
        "white",
    )

    draw = ImageDraw.Draw(image)

    draw.rectangle(
        (60, 80, 340, 300),
        outline="black",
        width=3,
    )
    draw.text(
        (90, 110),
        "STUDENT",
        fill="black",
    )
    draw.text(
        (90, 160),
        "student_id (PK)",
        fill="black",
    )
    draw.text(
        (90, 205),
        "name",
        fill="black",
    )

    draw.rectangle(
        (860, 80, 1140, 300),
        outline="black",
        width=3,
    )
    draw.text(
        (890, 110),
        "COURSE",
        fill="black",
    )
    draw.text(
        (890, 160),
        "course_id (PK)",
        fill="black",
    )
    draw.text(
        (890, 205),
        "title",
        fill="black",
    )

    draw.rectangle(
        (450, 400, 750, 640),
        outline="black",
        width=3,
    )
    draw.text(
        (490, 430),
        "ENROLLMENT",
        fill="black",
    )
    draw.text(
        (490, 480),
        "student_id (FK)",
        fill="black",
    )
    draw.text(
        (490, 525),
        "course_id (FK)",
        fill="black",
    )
    draw.text(
        (490, 570),
        "grade",
        fill="black",
    )

    draw.line(
        (300, 300, 500, 400),
        fill="black",
        width=3,
    )
    draw.text(
        (315, 310),
        "1",
        fill="black",
    )
    draw.text(
        (475, 370),
        "N",
        fill="black",
    )

    draw.line(
        (900, 300, 700, 400),
        fill="black",
        width=3,
    )
    draw.text(
        (875, 310),
        "1",
        fill="black",
    )
    draw.text(
        (700, 370),
        "N",
        fill="black",
    )

    image_buffer = io.BytesIO()

    image.save(
        image_buffer,
        format="PNG",
    )

    diagram_bytes = image_buffer.getvalue()

    test_project_result: ProjectResultState = {
        "node_id": 202,
        "tutorial_resource_id": "test-database-tutorial",
        "activity_type": "exercises",
        "title": "University Enrollment ER Diagram",
        "overview": (
            "Create an ER diagram for a simple "
            "university enrollment system."
        ),
        "activity_context": (
            "Students enroll in courses through "
            "an Enrollment entity."
        ),
        "learning_objective": (
            "Model entities, attributes, keys, "
            "and relationships in an ER diagram."
        ),
        "tasks": [
            "Create Student, Course, and Enrollment entities.",
            "Add appropriate primary and foreign keys.",
            "Represent the relationships between the entities.",
        ],
        "technical_requirements": [
            "Clearly label entities and attributes.",
            "Identify primary and foreign keys.",
            "Show relationship cardinalities.",
        ],
        "constraints": [
            "Use Student, Course, and Enrollment as the core entities.",
        ],
        "deliverables": [
            "An ER diagram showing the required database design.",
        ],
        "acceptance_criteria": [
            (
                "The diagram contains Student, Course, "
                "and Enrollment entities."
            ),
            (
                "Student has student_id as a primary key "
                "and Course has course_id as a primary key."
            ),
            (
                "Enrollment contains a grade attribute."
            ),
            (
                "Student contains an email attribute."
            ),
        ],
    }

    test_submission_evidence: dict[str, Any] = {
        "submission_file_name": "er_diagram.png",
        "submission_extension": ".png",
        "text_files": [],
        "visual_files": [
            {
                "file_name": "er_diagram.png",
                "source": "direct_upload",
                "media_type": "image/png",
                "content_bytes": diagram_bytes,
            }
        ],
        "student_note": "My ER diagram is attached.",
        "processing_notes": [],
    }

    test_input = {
        "project_result": test_project_result,
        "submission_evidence": test_submission_evidence,
    }

    assessment_output = run_project_assessment_agent(
        test_input
    )

    assessment_result = assessment_output[
        "project_assessment_result"
    ]

    score_result = assessment_output[
        "assessment_score"
    ]

    print("\nVISUAL PROJECT ASSESSMENT RESULT\n")
    pprint(assessment_result)

    print("\nDETERMINISTIC SCORE\n")
    pprint(score_result)