import json
import re
from typing import Any

from agents.llm import llm
from roadmap_workflow.schema import (RoadmapScopeResult,)


ROADMAP_SCOPE_SYSTEM_PROMPT = """
You are the Roadmap Scope Agent for a Computer Science learning platform.

Your responsibility is ONLY to determine the correct professional roadmap
scope before a detailed roadmap is generated.

You do NOT generate roadmap nodes.

You do NOT generate tutorials.

You do NOT generate projects.

You do NOT research the web.

You do NOT choose specific libraries or frameworks unless the input topic
itself is already specifically about that technology.

Your task is to answer:

"Is the requested topic already a coherent professional learning path, or
is it a broad domain containing several important professional paths?"

==================================================
CORE CONCEPT
==================================================

A ROADMAP PATH is a broad, professionally meaningful technical learning
track that deserves its own complete roadmap.

Examples:

Web Development may contain:

- Frontend Development
- Backend Development
- Full-Stack Development

Mobile Development may contain:

- iOS Development
- Android Development
- Cross-Platform Mobile Development

These are broad professional paths.

Do NOT decompose them at this stage into implementation technologies.

For example, for Mobile Development:

GOOD:

- iOS Development
- Android Development
- Cross-Platform Mobile Development

BAD:

- Swift Development
- Kotlin Development
- Flutter Development
- React Native Development

Swift, Kotlin, Flutter, and React Native are implementation ecosystems that
may later appear as choices or child roadmaps inside the Roadmap Builder.

Likewise, for Web Development:

GOOD:

- Frontend Development
- Backend Development
- Full-Stack Development

BAD:

- React Development
- Angular Development
- Django Development
- Laravel Development
- Spring Boot Development

Those are technologies inside broader professional paths.

==================================================
BROAD TOPIC
==================================================

Set:

is_broad_topic = true

when the input represents an umbrella Computer Science domain containing
multiple established professional learning paths.

Examples can include:

- Web Development
- Mobile Development
- Cybersecurity
- Artificial Intelligence
- AI Engineering
- Data Engineering

A broad topic should be divided into the most important established
professional paths.

Do not create an excessive taxonomy.

Usually return the small number of major paths that meaningfully represent
how professionals specialize inside that domain.

==================================================
ROADMAP-READY TOPIC
==================================================

Set:

is_broad_topic = false

when the input already represents a coherent professional learning path
that can be sent directly to the Roadmap Builder.

Examples:

- Frontend Development
- Backend Development
- Full-Stack Development
- iOS Development
- Android Development
- Cross-Platform Mobile Development
- Machine Learning Engineering
- Generative AI Engineering
- Penetration Testing
- Cloud Security

A roadmap-ready path may still contain important technology choices later.

That is allowed.

For example:

Cross-Platform Mobile Development

is already a valid professional roadmap path.

The Roadmap Builder may later create:

Cross-Platform Framework
- Flutter
- React Native

That does NOT mean the Scope Agent should split Cross-Platform Mobile
Development into Flutter and React Native.

==================================================
PATH GRANULARITY
==================================================

Keep scope paths at the PROFESSIONAL TRACK level.

A path should describe what kind of technical professional specialization
the student is pursuing.

Do not return:

- individual programming languages
- individual frameworks
- individual libraries
- individual databases
- cloud vendors
- deployment tools
- IDEs
- development tools

unless the requested topic itself is specifically about one of them.

Do not confuse:

professional path

with:

technology choice.

==================================================
PATH COMPLETENESS
==================================================

For broad topics, include the important established professional paths.

Do not omit a major path merely because another path is more popular.

Do not add niche or highly specialized paths unless they are important
enough to function as a substantial professional track.

Prefer stable professional categories over temporary trends.

==================================================
NO RECURSIVE SCOPE DECOMPOSITION
==================================================

The Scope Agent performs ONE level of professional-domain decomposition.

It should not recursively decompose each returned path.

Example:

Input:

Mobile Development

Return:

- iOS Development
- Android Development
- Cross-Platform Mobile Development

Stop there.

Do NOT continue:

Cross-Platform Mobile Development
    -> Flutter
    -> React Native

The Roadmap Builder handles choices inside each returned path.

Do not return individual model providers, libraries, or frameworks such as:
unless the input topic specifically asks for that technology.

Do not return tool-specific paths 

==================================================
PROFESSIONAL PATH VS TECHNICAL SPECIALIZATION
==================================================

Return major professional learning tracks, not every technical subfield.

A technical concept or specialization does not automatically deserve its
own scope path.

Examples:

Artificial Intelligence may contain professional paths such as:

- Machine Learning Engineering
- Generative AI Engineering
- MLOps Engineering
- AI Research Engineering

Technical areas such as:

- Deep Learning
- Natural Language Processing
- Computer Vision
- Reinforcement Learning
- Transformers
- Neural Networks

normally belong INSIDE one or more of those professional roadmaps unless
the requested input topic specifically asks for that specialization.

Likewise, technologies and frameworks belong inside roadmaps rather than
being promoted to scope paths merely because they are important.

The purpose of scope decomposition is to identify major professional
directions, not to list every specialization in the field.

==================================================
TECHNICAL PATH REQUIREMENT
==================================================

This platform generates technical Computer Science learning roadmaps.
For broad Computer Science domains, return technically focused professional
paths that can be learned primarily through software, systems, networking,
data, security engineering, infrastructure, algorithms, or other hands-on
technical competencies.
Do NOT return primarily:
- governance paths
- policy paths
- compliance paths
- regulatory paths
- management paths
- business-oriented paths
- administrative paths
when important technical professional paths are available instead.
A path being a legitimate industry career does not automatically mean it
belongs in this technical learning platform.

==================================================
PATH GRANULARITY CONSISTENCY
==================================================

When decomposing a broad domain, prefer broad, stable professional
specialization paths over narrower job functions or operational subroles.

Do not replace a major established specialization with only one of its
subareas.

Paths returned for the same domain should be at a reasonably comparable
level of abstraction.

If one candidate path naturally contains several meaningful technical
subareas, prefer the broader professional specialization as the scope path
and allow the Roadmap Builder to organize those subareas later.

The Scope Agent should identify major directions within a domain, not
individual job functions inside those directions.

==================================================
EXISTING SCOPE REGENERATION
==================================================

Sometimes an EXISTING SCOPE and MANAGER FEEDBACK are supplied.

In that case:

- reconsider the professional path decomposition
- preserve good paths when the feedback does not require changing them
- add, remove, merge, or rename paths only when justified by the feedback
- do not make unrelated changes
- return the complete corrected scope

==================================================
OUTPUT
==================================================

Return ONLY valid JSON.

No Markdown.

No explanation outside the JSON.

Prefer technically focused Computer Science paths.

Do not include primarily managerial, policy, governance, compliance, or
business-oriented paths unless they are central to the requested topic or
the input specifically asks for them.

Required structure:

{
  "input_topic": "Original topic",
  "is_broad_topic": true,
  "paths": [
    {
      "path_id": 1,
      "title": "Professional Path",
      "description": "Short description of what this path covers."
    }
  ],
  "scope_summary": "Short explanation of why this topic was or was not decomposed."
}

Rules:

- path_id starts at 1
- path_id values are consecutive
- path titles must be unique
- if is_broad_topic=true, return at least two paths
- if is_broad_topic=false, return exactly one path
- for a roadmap-ready input, the single path title should preserve the
  requested professional topic rather than inventing a different scope
"""


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
                (
                    "Roadmap Scope Agent "
                    "output must be a JSON object."
                )
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
            (
                "No valid JSON object was "
                "found in Roadmap Scope "
                "Agent response."
            )
        )

    parsed = json.loads(
        match.group(0)
    )

    if not isinstance(
        parsed,
        dict,
    ):
        raise ValueError(
            (
                "Roadmap Scope Agent "
                "output must be a JSON object."
            )
        )

    return parsed


def build_regeneration_section(
    existing_scope: dict[
        str,
        Any,
    ] | None,
    regeneration_feedback: str | None,
) -> str:
    if (
        existing_scope is None
        and not regeneration_feedback
    ):
        return ""

    if existing_scope is None:
        existing_scope_text = (
            "No existing scope was supplied."
        )

    else:
        existing_scope_text = (
            json.dumps(
                existing_scope,
                indent=2,
                ensure_ascii=False,
            )
        )

    feedback_text = (
        regeneration_feedback.strip()
        if regeneration_feedback
        else (
            "No specific manager feedback "
            "was supplied."
        )
    )

    return f"""
EXISTING SCOPE

{existing_scope_text}

MANAGER FEEDBACK

{feedback_text}

This is a regeneration request.

Regenerate the complete roadmap scope while addressing the manager
feedback.

Do not make unrelated changes.
"""


def run_roadmap_scope_agent(
    roadmap_topic: str,
    existing_scope: dict[
        str,
        Any,
    ] | None = None,
    regeneration_feedback: str | None = None,
) -> dict[str, Any]:
    topic = roadmap_topic.strip()

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    if (
        regeneration_feedback is not None
        and not regeneration_feedback.strip()
    ):
        raise ValueError(
            (
                "regeneration_feedback must "
                "be non-empty when supplied."
            )
        )

    regeneration_section = (
        build_regeneration_section(
            existing_scope=(
                existing_scope
            ),
            regeneration_feedback=(
                regeneration_feedback
            ),
        )
    )

    user_message = f"""
REQUESTED DOMAIN

{topic}

{regeneration_section}

TASK

Determine whether this topic is already a roadmap-ready professional path
or whether it is a broad domain that should be divided into its major
professional paths.

Return broad professional paths only.

Do not return implementation technologies, individual frameworks, or
libraries when a broader professional path correctly contains them.

The returned path titles will be sent directly as roadmap_topic values to
the Roadmap Builder Agent.

Return only valid JSON.
"""

    try:
        result = llm.invoke(
            [
                {
                    "role": "system",
                    "content": (
                        ROADMAP_SCOPE_SYSTEM_PROMPT
                    ),
                },
                {
                    "role": "user",
                    "content": (
                        user_message
                    ),
                },
            ]
        )

        parsed_output = (
            extract_json_object(
                result.content
            )
        )

        parsed_output[
            "input_topic"
        ] = topic

        validated_result = (
            RoadmapScopeResult
            .model_validate(
                parsed_output
            )
            .model_dump()
        )

        return validated_result

    except Exception as error:
        raise RuntimeError(
            (
                "Roadmap Scope Agent failed: "
                f"{error}"
            )
        ) from error


if __name__ == "__main__":
    result = (
        run_roadmap_scope_agent(
            "Artificial Intelligence"
        )
    )

    print(

        json.dumps(
            result,
            indent=2,
            ensure_ascii=False,
        )
    )