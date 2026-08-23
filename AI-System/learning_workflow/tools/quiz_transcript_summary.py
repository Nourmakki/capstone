import os
from typing import Any
from google import genai
from google.genai import types
from pydantic import ( BaseModel,ConfigDict,Field,)


GEMINI_SUMMARIZER_MODEL = (
    "gemini-3.6-flash"
)

MAX_ITEMS_PER_FIELD = 12


# =====================================================================
# STRUCTURED SUMMARY RESULT
# =====================================================================

class QuizTranscriptSummary(BaseModel):

    model_config = ConfigDict(
        extra="forbid"
    )

    summary: str

    important_concepts: list[str] = Field(
        default_factory=list,
        max_length=MAX_ITEMS_PER_FIELD,
    )

    rules_and_relationships: list[str] = Field(
        default_factory=list,
        max_length=MAX_ITEMS_PER_FIELD,
    )

    examples_and_applications: list[str] = Field(
        default_factory=list,
        max_length=MAX_ITEMS_PER_FIELD,
    )

    code_or_commands: list[str] = Field(
        default_factory=list,
        max_length=MAX_ITEMS_PER_FIELD,
    )

    formulas_or_calculations: list[str] = Field(
        default_factory=list,
        max_length=MAX_ITEMS_PER_FIELD,
    )

    caveats_and_limits: list[str] = Field(
        default_factory=list,
        max_length=MAX_ITEMS_PER_FIELD,
    )


# =====================================================================
# SUMMARIZATION PROMPT
# =====================================================================

QUIZ_TRANSCRIPT_SUMMARY_PROMPT = """
You extract the most important educational content from a Computer
Science tutorial transcript.

The extracted content will be used by a Quiz Agent to generate questions
that are grounded in what the tutorial actually teaches.

Use ONLY the supplied transcript.

Do not add concepts merely because they are generally related to the
topic.

Ignore non-educational content such as:
- greetings
- subscribe or like requests
- sponsorships
- advertisements
- course promotions
- creator commentary unrelated to the technical learning content
- repeated filler

Focus on knowledge that is important for understanding the subject.

Extract:

important_concepts:
The main concepts, definitions, mechanisms, techniques, or ideas that
the tutorial meaningfully teaches.

rules_and_relationships:
Important behavior, cause-and-effect relationships, comparisons,
technical rules, constraints, or relationships explicitly explained.

examples_and_applications:
Meaningful technical examples, demonstrations, use cases, or scenarios
used to teach or apply the concepts.

code_or_commands:
Important code snippets, syntax behavior, commands, APIs,
configuration, or implementation patterns taught by the tutorial.
Return an empty list when none are taught.

formulas_or_calculations:
Important formulas, quantitative rules, equations, calculations, or
mathematical procedures taught by the tutorial.
Return an empty list when none are taught.

caveats_and_limits:
Important exceptions, limitations, tradeoffs, warnings, conditions, or
edge cases taught by the tutorial.

Keep the result concise but preserve enough technical detail for the
Quiz Agent to generate accurate conceptual, applied, code-analysis, or
calculation questions when the transcript supports them.

OUTPUT

Return the extracted learning evidence using exactly these fields:

{
  "summary": "A concise overall summary of the important technical content taught in the tutorial.",

  "important_concepts": [
    "Important concept or definition meaningfully taught in the tutorial"
  ],

  "rules_and_relationships": [
    "Important behavior, relationship, comparison, constraint, or cause-and-effect rule taught in the tutorial"
  ],

  "examples_and_applications": [
    "Meaningful technical example, demonstration, use case, or application taught in the tutorial"
  ],

  "code_or_commands": [
    "Important code behavior, syntax, command, API, configuration, or implementation pattern taught in the tutorial"
  ],

  "formulas_or_calculations": [
    "Important formula, equation, quantitative rule, or calculation procedure taught in the tutorial"
  ],

  "caveats_and_limits": [
    "Important limitation, exception, warning, tradeoff, condition, or edge case taught in the tutorial"
  ]
}

Use an empty array for a category when the transcript does not provide
meaningful evidence for that category.

Do not invent an item merely to fill a category.

Do not return the raw transcript.

Do not create quiz questions.

Do not add any fields outside this structure.
Do not create quiz questions.

Do not invent information.
"""


# =====================================================================
# GEMINI IMPORTANT-POINTS TOOL
# =====================================================================

def summarize_quiz_transcript(
    full_text: str,
) -> dict[str, Any] | None:

    if (
        not isinstance(full_text, str)
        or not full_text.strip()
    ):
        return None

    api_key = os.getenv(
        "GEMINI_SUMMARIZER_API_KEY"
    )

    if not api_key:
        return None

    try:
        client = genai.Client(
            api_key=api_key
        )

        response = (
            client.models.generate_content(
                model=GEMINI_SUMMARIZER_MODEL,
                contents=f"""
{QUIZ_TRANSCRIPT_SUMMARY_PROMPT}


FULL TUTORIAL TRANSCRIPT

{full_text}
""",
                config=types.GenerateContentConfig(
                    temperature=0.1,
                    response_mime_type=(
                        "application/json"
                    ),
                    response_json_schema=(
                    QuizTranscriptSummary.model_json_schema()
                    ),
                ),
            )
        )

        if response.parsed is not None:

            if isinstance(
                response.parsed,
                QuizTranscriptSummary,
            ):
                validated_summary = (
                    response.parsed
                )

            else:
                validated_summary = (
                    QuizTranscriptSummary.model_validate(
                        response.parsed
                    )
                )

        else:

            if (
                not isinstance(
                    response.text,
                    str,
                )
                or not response.text.strip()
            ):
                return None

            validated_summary = (
                QuizTranscriptSummary.model_validate_json(
                    response.text
                )
            )

    except Exception:
        return None

    return {
        "source": "gemini_transcript_summary",
        "model": GEMINI_SUMMARIZER_MODEL,
        "learning_evidence": (
            validated_summary.model_dump()
        ),
    }