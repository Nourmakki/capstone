import json
import re
from copy import deepcopy
from typing import Any

from agents.llm import llm
from roadmap_workflow.schema import (
    RoadmapAgentResult,
)


ROADMAP_BUILDER_SYSTEM_PROMPT = """
You are the Roadmap Builder Agent for a Computer Science learning platform.

Your task is to build ONE clear, professional learning roadmap for the
requested Computer Science topic.

The roadmap represents the technical knowledge a student should learn to
be ready for current entry-level professional work in that topic.

Project experience, portfolio building, CV preparation, interviewing,
soft skills, and job-search preparation are outside this roadmap.

You have strong established Computer Science and software-engineering
knowledge.

Use that established knowledge to construct the stable professional core
of the requested roadmap.

You also receive current external research.

The external research is SUPPORTING CURRENTNESS EVIDENCE.

Use it to:

- verify that the roadmap remains professionally current
- identify meaningful modern ecosystem changes
- confirm technologies or practices that have become important
- avoid obsolete recommendations
- support current professional relevance

Do NOT treat search results as an exhaustive curriculum.

A foundational or established competency must not disappear merely because
it was absent from the retrieved search results.

Likewise, do not add a roadmap node merely because one search result
mentions it.

The roadmap should first be technically and educationally coherent, then
modernized using the supplied evidence.

==================================================
TOP PRIORITY
==================================================

Build a well-structured roadmap containing the important technical
competencies that a professional in this topic is expected to know today.

The roadmap should be stable and conservative.

Do not rebuild the field around temporary trends.

When current evidence is ambiguous, prefer the established professional
core over novelty.

==================================================
NODE ADMISSION RULE
==================================================

Before creating ANY roadmap node, verify all of these:

1. DIRECT DOMAIN RELEVANCE

The competency must genuinely belong to the requested topic.

2. PROFESSIONAL IMPORTANCE

The competency must represent knowledge or technical capability that
meaningfully contributes to being professionally competent in this topic.

3. STABLE TECHNICAL VALUE

A foundational or established competency may belong in the roadmap even
when it is not explicitly mentioned by the retrieved research.

Do not let search variability cause important fundamentals to randomly
appear or disappear.

4. CURRENTNESS CHECK

Use the supplied research to verify whether important modern technologies,
standards, practices, or ecosystem changes should affect the roadmap.

Research verifies and modernizes the curriculum.

Research does not define the entire curriculum.

5. SUBSTANTIAL LEARNING VALUE

A node must represent a meaningful learning competency.

Do not create nodes for tiny features, commands, individual APIs, minor
configuration decisions, or incidental tools.

6. NOT GENERIC DEVELOPER CLUTTER

Do not create roadmap nodes for supporting tools that do not define
expertise in the requested topic.

Examples that must NOT become roadmap nodes merely because developers use
them:

- GitHub
- GitLab
- Bitbucket
- IDEs
- code editors
- AI assistants
- AI coding tools
- Jira
- Slack
- Trello
- Notion
- generic collaboration tools
- generic productivity tools

Exception:

If the requested roadmap topic itself is specifically about one of these
tools or practices, then it may obviously be taught.

7. NOT OPTIONAL NICHE MATERIAL

Do not include niche specializations, advanced optional technologies, or
temporary trends in the professional core unless they are broadly important
to the requested topic.

==================================================
PROFESSIONAL CORE
==================================================

Build the roadmap around the important technical knowledge expected from a
professional in the requested domain.

Do not interpret job readiness as knowing every technology or ecosystem
tool.

Interpret it as:

"The student has learned the important technical core of this role and can
then demonstrate that knowledge through projects."

Before returning the roadmap, ask:

"If a student completes these competencies and practices them through
projects, have I covered the important technical knowledge expected from a
current professional in this role?"

If an important recurring domain competency is missing, add it.

If a node is merely useful but not important enough for the professional
core, remove it.

==================================================
CURRENTNESS
==================================================

The roadmap must reflect the current roadmap year shown in the supplied
research.

Use current evidence to modernize established knowledge.

For example, a newer language, framework, standard, architecture pattern,
or professional practice may deserve roadmap representation when it has
become a mature and professionally important part of the domain.

Do not add something only because it is new.

Do not add AI-related material simply because AI is popular.

AI belongs only when it genuinely belongs to the requested domain.

==================================================
ROADMAP STRUCTURE
==================================================

Each node represents ONE coherent learning competency.

There is no target node count.

Use as many nodes as are genuinely required to represent the important
professional curriculum.

Do not create filler nodes to make the roadmap longer.

Do not omit important competencies merely to make the roadmap shorter.

Do not merge independent major competencies into one vague node.

Do not split one competency into many tiny nodes merely to make the roadmap
look detailed.

==================================================
STANDARD NODES
==================================================

Use type "standard" when the student should learn the competency itself.

Examples can include:

- foundational concepts
- important languages
- architectures
- domain-specific practices
- major professional technical competencies

==================================================
CHOICE NODES
==================================================

Use type "choice" only when multiple current alternatives serve
substantially the SAME role in the learning path.

Example:

Frontend Framework
- React
- Vue
- Angular
- Svelte

Do not use a choice node for technologies that should actually be learned
sequentially.

Do not create unnecessary choices.

Exactly one alternative must be recommended.

==================================================
CHOICE RECOMMENDATION
==================================================

When specific choice-demand research is supplied:

1. Prefer the alternative with the strongest current employer/company
   demand supported by the supplied evidence.
2. If employer-demand evidence does not clearly distinguish the choices,
   use professional adoption as the secondary factor.
3. Use maintenance and ecosystem health only as a final supporting factor.

Do not invent:

- job-posting counts
- percentages
- rankings
- market-share values
- quantitative advantages

Do not use unsupported claims such as:

- highest demand
- leads the market
- dominates
- most requested
- explosive growth
- industry standard

unless the supplied evidence explicitly establishes that exact claim.

When exact comparative evidence is unavailable, use careful qualitative
wording such as:

"Current hiring evidence shows strong employer demand and broad
professional adoption."

Keep recommendation_reason short.

CHOICE COMPLETENESS

When a choice represents major professional ecosystems, include the
materially important established alternatives for the role.

Do not arbitrarily stop after two or three alternatives.

Do not omit a major established alternative merely because another
alternative has stronger current employer demand.

Recommendation and inclusion are different decisions:

- inclusion = this is a meaningful professional path
- recommended = this is the single path currently recommended

Example:

Backend Language / Ecosystem may reasonably include major paths such as
Python, TypeScript/Node.js, Java, C#/.NET, and Go when they remain
professionally relevant.

The example illustrates the rule only. Apply the same reasoning to other
Computer Science domains.

For EVERY choice node, exactly one alternative must have:

recommended = true

All other alternatives in that same choice node must have:

recommended = false

This rule applies even when specific choice-demand research has not yet
been supplied.

When specific choice-demand research is not supplied, make one conservative
provisional recommendation using the available evidence.

Never return a choice node with zero recommended alternatives.

Never return a choice node with multiple recommended alternatives.

Every recommended alternative must contain a non-empty
recommendation_reason.

==================================================
CHOICE RECOMMENDATION LANGUAGE
==================================================

Recommendation reasons must use conservative qualitative wording.

Unless the supplied evidence explicitly contains a verified comparative
measurement, DO NOT use:

- highest
- lowest
- dominates
- dominant
- leads
- leading
- most demanded
- most requested
- significantly higher
- significantly lower
- explosive growth
- industry standard
- professional standard
- largest market
- largest community
- exact percentages
- exact job counts

Prefer wording such as:

- "shows strong employer demand"
- "has broad professional adoption"
- "is widely used in enterprise environments"
- "has strong current ecosystem support"
- "appears frequently in current professional practice"

A recommendation does not require proving that the selected alternative is
universally number one.

It only requires identifying the best-supported recommendation among the
available alternatives.

==================================================
GENUINE CHOICE REQUIREMENT
==================================================

Create a choice node only when the alternatives are genuine substitutes
for substantially the same role in the learner's path.

Do not create a choice merely because multiple technologies are useful
within the domain.

If technologies are commonly complementary, sequential, or used for
different responsibilities, represent them as separate competencies,
learning outcomes, or later specialization content rather than forcing
the learner to choose one.

Before creating a choice, ask:

"Would a professional normally choose one of these alternatives as the
primary way to fulfill the same technical role?"

If not, do not model them as a choice.

==================================================
SEPARATE CHILD ROADMAPS
==================================================

For a PARENT roadmap, decide whether each choice alternative needs a
substantial standalone curriculum.

Set:

separate_roadmap = true

when the alternative represents a substantial multi-stage learning path,
such as a major language, framework, platform, specialization, or similar
professional technology.

Set:

separate_roadmap = false

when the alternative is narrow enough to learn as a focused unit, such as:

- a small library
- a configuration strategy
- a deployment option
- a testing library
- a state-management option
- a minor implementation approach

The field means:

"Does this alternative require its own structured sequence of
competencies?"

It does NOT mean:

"Is this alternative popular?"

==================================================
PARENT / CHILD BOUNDARY
==================================================

When the parent roadmap contains a major choice, technology-specific
progression belongs in the selected child roadmap.

The remaining parent nodes should represent shared domain competencies that
apply regardless of which major alternative is selected.

For example, a Backend Development parent may contain shared competencies
such as:

- database principles
- API design
- authentication and authorization
- caching and performance
- backend security
- testing principles
- production and deployment fundamentals

Those are shared Backend competencies, not Python-only or Node-only
competencies.

Likewise, avoid creating a vague generic node before a major choice when
its actual content depends on the selected alternative.

Example:

If the student must choose Python, Node.js, Java, or Go, language-specific
fundamentals normally belong in the corresponding child rather than in a
generic "programming language fundamentals" parent node.

==================================================
CHILD ROADMAP CONTINUATION
==================================================

When FULL PARENT ROADMAP CONTEXT is supplied, the roadmap being generated
is a CHILD roadmap.

The child must focus on knowledge specific to the selected alternative.

The child receives two forms of parent information:

1. competencies already completed before entering the choice
2. the complete parent roadmap

Competencies completed before entering the choice are genuine prerequisite
knowledge.

Other shared competencies elsewhere in the parent may not yet be completed,
but the child must still avoid recreating them as generic child nodes.

Example:

If the Backend parent already teaches:

- relational databases
- API design
- authentication
- caching
- security
- testing principles
- production deployment

the Python child should not create another generic "API Design" or
"Backend Security" node.

The child MAY teach technology-specific application of a parent competency.

Examples:

- Python async database integration
- Node-specific middleware implementation
- React-specific component testing
- Go-specific HTTP server patterns

Do not duplicate the shared parent curriculum.

==================================================
CHILD ROADMAP DEPTH LIMIT
==================================================

A child roadmap must NEVER generate another child roadmap.

The current platform supports:

Parent roadmap
    -> direct child roadmap

The current platform does NOT support:

Parent roadmap
    -> child roadmap
        -> grandchild roadmap

A child roadmap may still contain genuine choice nodes.

However, inside a CHILD roadmap:

EVERY choice alternative MUST have:

separate_roadmap = false

This rule applies even when the alternative could theoretically support a
larger curriculum.

Example:

Backend Development
    -> Python child
        -> Python Web Framework
            - FastAPI
            - Django

FastAPI and Django may remain genuine alternatives, but BOTH must have:

separate_roadmap = false

No grandchildren are allowed.

==================================================
PREREQUISITES
==================================================

The roadmap must form a valid directed acyclic learning structure.

Node IDs start at 1 and are consecutive.

Order values start at 1 and are consecutive.

Only include DIRECT prerequisites.

A prerequisite must always appear earlier than the dependent node.

Do not make independent competencies prerequisites merely because one
appears earlier in the roadmap.

A roadmap may branch.

==================================================
LEARNING OUTCOMES
==================================================

Each node must contain concise concrete learning outcomes.

Describe what the student should be able to understand, implement, design,
analyze, evaluate, or apply.

Avoid vague outcomes such as:

- learn the basics
- understand the topic
- know how it works

==================================================
DESCRIPTIONS
==================================================

Descriptions define what belongs inside the competency.

They are not tutorials.

Do not use marketing language.

Do not discuss popularity except when explaining a choice recommendation.

==================================================
FRESHNESS SUMMARY
==================================================

Briefly explain how the roadmap reflects current professional practice.

Use careful evidence-based wording.

Do not invent statistics.

Do not claim universal requirements unless the evidence truly supports
them.

==================================================
FINALIZATION MODE
==================================================

Sometimes an EXISTING ROADMAP DRAFT is supplied together with specific
choice-demand research.

In this mode:

- preserve roadmap structure
- preserve nodes
- preserve node IDs
- preserve node titles
- preserve node types
- preserve order
- preserve prerequisites
- preserve learning outcomes
- preserve choices
- preserve separate_roadmap values

Only finalize:

- recommended
- recommendation_reason

Do not rebuild the roadmap during finalization.

==================================================
TESTING COMPLETENESS
==================================================
Every roadmap should include the testing and quality competencies that
are professionally necessary for that specific technology or domain,
unless testing was genuinely and sufficiently completed in the parent
roadmap.

For child roadmaps, prefer ecosystem-specific testing practices rather
than repeating generic testing concepts.

==================================================
OUTPUT
==================================================

Return ONLY valid JSON.

No Markdown.
No explanation outside JSON.
No extra fields.

NODE TYPE CONSISTENCY:

A node with type "standard" MUST NOT contain a choices field.

A node containing choices MUST have type "choice".

Never return choices inside a standard node.

Required structure:

{
  "roadmap_topic": "Requested topic",
  "title": "Requested topic",
  "description": "Roadmap description",
  "nodes": [
    {
      "node_id": 1,
      "type": "standard",
      "title": "Node title",
      "description": "Node description",
      "order": 1,
      "prerequisite_node_ids": [],
      "learning_outcomes": [
        "Outcome 1",
        "Outcome 2"
      ]
    },
    {
      "node_id": 2,
      "type": "choice",
      "title": "Choice stage",
      "description": "Choice stage description",
      "order": 2,
      "prerequisite_node_ids": [1],
      "learning_outcomes": [
        "Outcome 1",
        "Outcome 2"
      ],
      "choices": [
        {
          "choice_id": 1,
          "title": "Alternative A",
          "description": "Alternative description",
          "separate_roadmap": true,
          "recommended": true,
          "recommendation_reason": "Short evidence-supported reason"
        },
        {
          "choice_id": 2,
          "title": "Alternative B",
          "description": "Alternative description",
          "separate_roadmap": true,
          "recommended": false,
          "recommendation_reason": null
        }
      ]
    }
  ],
  "freshness_summary": "Short current-professional-practice summary"
}
"""


GENERIC_SUPPORT_NODE_PATTERNS = [
    r"\bgithub\b",
    r"\bgitlab\b",
    r"\bbitbucket\b",
    r"\bvisual studio code\b",
    r"\bvs code\b",
    r"\bcode editors?\b",
    r"\bides?\b",
    r"\bchatgpt\b",
    r"\bcopilot\b",
    r"\bai assistants?\b",
    r"\bai coding tools?\b",
    r"\bjira\b",
    r"\bslack\b",
    r"\btrello\b",
    r"\bnotion\b",
]


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
        text_parts: list[
            str
        ] = []

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
                    str(item)
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
                    "Roadmap Builder Agent "
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
                "found in Roadmap Builder "
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
                "Roadmap Builder Agent "
                "output must be a JSON object."
            )
        )

    return parsed


def normalize_text(
    value: str,
) -> str:
    return re.sub(
        r"\s+",
        " ",
        value.casefold(),
    ).strip()


def validate_no_generic_support_nodes(
    roadmap_topic: str,
    roadmap: dict[str, Any],
) -> None:
    normalized_topic = (
        normalize_text(
            roadmap_topic
        )
    )

    for node in roadmap[
        "nodes"
    ]:
        node_title = (
            normalize_text(
                node["title"]
            )
        )

        for pattern in (
            GENERIC_SUPPORT_NODE_PATTERNS
        ):
            if not re.search(
                pattern,
                node_title,
                flags=re.IGNORECASE,
            ):
                continue

            if re.search(
                pattern,
                normalized_topic,
                flags=re.IGNORECASE,
            ):
                continue

            raise ValueError(
                (
                    "Roadmap contains a generic "
                    "support-tool node that does "
                    "not belong in this domain: "
                    f"{node['title']}"
                )
            )

def normalize_roadmap_node_types(
    roadmap: dict[str, Any],
) -> dict[str, Any]:
    result = deepcopy(
        roadmap
    )

    for node in result.get(
        "nodes",
        [],
    ):
        choices = node.get(
            "choices"
        )

        if (
            isinstance(
                choices,
                list,
            )
            and len(choices) >= 2
        ):
            node["type"] = "choice"
            continue

        if (
            node.get("type")
            == "standard"
            and "choices" in node
            and (
                choices is None
                or choices == []
            )
        ):
            node.pop(
                "choices",
                None,
            )

    return result

def enforce_child_depth_limit(
    roadmap: dict[str, Any],
) -> dict[str, Any]:
    result = deepcopy(
        roadmap
    )

    for node in result[
        "nodes"
    ]:
        if node[
            "type"
        ] != "choice":
            continue

        for choice in node[
            "choices"
        ]:
            choice[
                "separate_roadmap"
            ] = False

    return result


def build_choice_research_section(
    choice_demand_research: str | None,
) -> str:
    if choice_demand_research:
        return f"""
SPECIFIC CHOICE-DEMAND RESEARCH

{choice_demand_research}

Use this evidence only to finalize the recommended alternative inside the
matching choice node.

Employer demand is the primary factor.

Professional adoption is the secondary factor.

Do not invent quantitative rankings, percentages, market-share values, or
job-posting numbers.

Do not use unsupported superlatives such as "highest demand", "dominates",
"leads the market", or "most requested".

Keep recommendation_reason short and evidence-supported.
"""

    return """
SPECIFIC CHOICE-DEMAND RESEARCH

Not supplied.

Build genuine choice nodes where appropriate.

Use established professional knowledge and the supplied currentness
research for a provisional recommendation.

Do not invent comparative employer-demand claims.
"""


def build_existing_roadmap_section(
    existing_roadmap: dict[
        str,
        Any,
    ] | None,
) -> str:
    if existing_roadmap:
        return f"""
EXISTING ROADMAP DRAFT

{json.dumps(
    existing_roadmap,
    indent=2,
    ensure_ascii=False,
)}

This roadmap has already been generated.

Do not rebuild it.

Preserve the complete structure.

Only finalize recommended and recommendation_reason using the specific
choice-demand research.
"""

    return """
EXISTING ROADMAP DRAFT

Not supplied.

Generate the complete roadmap normally.
"""

DESTRUCTIVE_REVISION_TERMS = (
    "remove",
    "delete",
    "drop",
    "omit",
    "merge",
    "replace",
    "unnecessary",
    "not important",
    "should not",
    "does not belong",
    "do not include",
)


def build_revision_section(
    existing_roadmap: dict[str, Any] | None,
    revision_feedback: str | None,
) -> str:
    if revision_feedback is None:
        return ""

    if existing_roadmap is None:
        raise ValueError(
            "Roadmap revision requires an existing roadmap."
        )

    feedback = revision_feedback.strip()

    if not feedback:
        raise ValueError(
            "Roadmap revision feedback must be non-empty."
        )

    return f"""
ROADMAP REVISION MODE

EXISTING ROADMAP

{json.dumps(
    existing_roadmap,
    indent=2,
    ensure_ascii=False,
)}

MANAGER FEEDBACK

{feedback}

Revise the existing roadmap to address the manager feedback.

This is NOT a fresh roadmap generation.

Preserve all existing correct curriculum.

Make the smallest coherent set of changes required by the feedback.

Do not remove, merge, rename, reorder, or rewrite unrelated competencies.

Do not change existing choices unless the feedback requires that change.

Do not change separate_roadmap values unless the feedback requires a
structural choice change.

If the manager identifies a missing competency, add that competency while
preserving existing correct competencies.

When adding a node, update node IDs, order values, and prerequisites only
where necessary to keep the roadmap valid.

When the manager explicitly asks to remove, replace, merge, or correct
something, make only the changes necessary to satisfy that request.

Return the COMPLETE revised roadmap, not a patch.
"""


def feedback_allows_removal(
    revision_feedback: str,
) -> bool:
    normalized_feedback = normalize_text(
        revision_feedback
    )

    return any(
        term in normalized_feedback
        for term in DESTRUCTIVE_REVISION_TERMS
    )


def validate_conservative_revision(
    existing_roadmap: dict[str, Any],
    revised_roadmap: dict[str, Any],
    revision_feedback: str,
) -> None:
    if feedback_allows_removal(
        revision_feedback
    ):
        return

    existing_node_titles = {
        normalize_text(
            node["title"]
        )
        for node in existing_roadmap[
            "nodes"
        ]
    }

    revised_node_titles = {
        normalize_text(
            node["title"]
        )
        for node in revised_roadmap[
            "nodes"
        ]
    }

    removed_nodes = (
        existing_node_titles
        - revised_node_titles
    )

    if removed_nodes:
        raise ValueError(
            "Conservative roadmap revision removed existing nodes even though "
            "the manager feedback did not request removal: "
            + ", ".join(
                sorted(
                    removed_nodes
                )
            )
        )

    existing_choices: set[
        tuple[str, str]
    ] = set()

    for node in existing_roadmap[
        "nodes"
    ]:
        if node[
            "type"
        ] != "choice":
            continue

        node_title = normalize_text(
            node["title"]
        )

        for choice in node[
            "choices"
        ]:
            existing_choices.add(
                (
                    node_title,
                    normalize_text(
                        choice["title"]
                    ),
                )
            )

    revised_choices: set[
        tuple[str, str]
    ] = set()

    for node in revised_roadmap[
        "nodes"
    ]:
        if node[
            "type"
        ] != "choice":
            continue

        node_title = normalize_text(
            node["title"]
        )

        for choice in node[
            "choices"
        ]:
            revised_choices.add(
                (
                    node_title,
                    normalize_text(
                        choice["title"]
                    ),
                )
            )

    removed_choices = (
        existing_choices
        - revised_choices
    )

    if removed_choices:
        removed_choice_names = [
            f"{node_title} -> {choice_title}"
            for (
                node_title,
                choice_title,
            ) in sorted(
                removed_choices
            )
        ]

        raise ValueError(
            "Conservative roadmap revision removed existing choices even "
            "though the manager feedback did not request removal: "
            + ", ".join(
                removed_choice_names
            )
        )

def build_parent_context_section(
    roadmap_topic: str,
    completed_parent_competencies: list[
        str
    ] | None,
    parent_roadmap_context: dict[
        str,
        Any,
    ] | None,
) -> str:
    if (
        parent_roadmap_context
        is None
    ):
        if not completed_parent_competencies:
            return ""

        completed_section = "\n".join(
            f"- {competency}"
            for competency in (
                completed_parent_competencies
            )
        )

        return f"""
PARENT LEARNING CONTEXT

The learner already completed:

{completed_section}

Treat these as prerequisite knowledge.

Do not repeat them as roadmap nodes.

Start with the first meaningful competency specific to:

{roadmap_topic}
"""

    completed_parent_competencies = (
        completed_parent_competencies
        or []
    )

    if completed_parent_competencies:
        completed_section = "\n".join(
            f"- {competency}"
            for competency in (
                completed_parent_competencies
            )
        )

    else:
        completed_section = "- None"

    return f"""
FULL PARENT ROADMAP CONTEXT

This is a CHILD roadmap.

CHILD TOPIC:

{roadmap_topic}

COMPETENCIES ALREADY COMPLETED BEFORE ENTERING THIS CHILD:

{completed_section}

FULL PARENT ROADMAP:

{json.dumps(
    parent_roadmap_context,
    indent=2,
    ensure_ascii=False,
)}

The completed competencies above are genuine prerequisite knowledge.

The full parent roadmap is also supplied so you can identify shared domain
competencies that belong in the parent rather than being duplicated inside
this child.

Do not recreate generic versions of competencies already owned by the
parent.

A parent competency may still be applied in a technology-specific way
inside a child-specific node or learning outcome.

IMPORTANT:

This child may contain choice nodes, but EVERY choice alternative inside
this child MUST have:

separate_roadmap = false

The system does not support grandchildren.
"""


def run_roadmap_builder_agent(
    roadmap_topic: str,
    current_domain_research: dict[str, Any],
    choice_demand_research: str | None = None,
    existing_roadmap: dict[str, Any] | None = None,
    completed_parent_competencies: list[str] | None = None,
    parent_roadmap_context: dict[str, Any] | None = None,
    revision_feedback: str | None = None,
) -> dict[str, Any]:
    topic = roadmap_topic.strip()

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    research_summary = str(
        current_domain_research.get(
            "summary",
            "",
        )
    ).strip()

    if not research_summary:
        raise ValueError(
            "Roadmap Builder Agent requires current-domain research."
        )

    revision_mode = (
        revision_feedback is not None
    )

    if revision_mode:
        if existing_roadmap is None:
            raise ValueError(
                "Roadmap revision requires an existing roadmap."
            )

        if not revision_feedback.strip():
            raise ValueError(
                "Roadmap revision feedback must be non-empty."
            )

        if choice_demand_research is not None:
            raise ValueError(
                "Roadmap revision and choice finalization must run as separate steps."
            )

    research_year = (
        current_domain_research.get(
            "research_year"
        )
    )

    choice_research_section = (
        build_choice_research_section(
            choice_demand_research
        )
    )

    if revision_mode:
        existing_roadmap_section = ""
    else:
        existing_roadmap_section = (
            build_existing_roadmap_section(
                existing_roadmap
            )
        )

    revision_section = (
        build_revision_section(
            existing_roadmap=(
                existing_roadmap
            ),
            revision_feedback=(
                revision_feedback
            ),
        )
    )

    parent_context_section = (
        build_parent_context_section(
            roadmap_topic=topic,
            completed_parent_competencies=(
                completed_parent_competencies
            ),
            parent_roadmap_context=(
                parent_roadmap_context
            ),
        )
    )

    is_child_roadmap = (
        parent_roadmap_context
        is not None
    )

    if revision_mode:
        task_instruction = """
Revise the existing professional technical roadmap according to the manager
feedback.

Preserve all existing correct curriculum and make only the smallest
coherent changes needed to address the feedback.
"""
    else:
        task_instruction = """
Produce the professional technical roadmap for this topic.
"""

    user_message = f"""
ROADMAP TOPIC

{topic}

ROADMAP YEAR

{research_year}

RESEARCH

{research_summary}

{parent_context_section}

{choice_research_section}

{existing_roadmap_section}

{revision_section}

TASK

{task_instruction}

Priorities, in order:

1. Correct domain scope.
2. Complete professional core.
3. Current professional relevance.
4. Clean educational structure.
5. No unnecessary nodes.
6. No generic developer-tool clutter.
7. Genuine alternatives represented as choices.
8. Direct prerequisite relationships only.

For every choice alternative, carefully decide separate_roadmap using the
standalone-curriculum rule.

Major alternatives such as full frameworks, languages, platforms, or other
multi-stage professional technologies should not be incorrectly marked
false merely because they are named technologies.

For EVERY choice node:

- exactly one alternative must have recommended=true
- all other alternatives must have recommended=false
- the recommended alternative must have a non-empty recommendation_reason

If this is a child roadmap, all choices inside this child must have
separate_roadmap=false.

The final roadmap should represent the technical curriculum that a student
should complete before demonstrating the knowledge through projects.

Return only valid JSON.
"""

    maximum_attempts = 2
    last_error: Exception | None = None

    for attempt in range(
        1,
        maximum_attempts + 1,
    ):
        messages = [
            {
                "role": "system",
                "content": (
                    ROADMAP_BUILDER_SYSTEM_PROMPT
                ),
            },
            {
                "role": "user",
                "content": (
                    user_message
                ),
            },
        ]

        if last_error is not None:
            messages.append(
                {
                    "role": "user",
                    "content": f"""
Your previous roadmap response failed validation.

VALIDATION ERROR

{last_error}

Generate the COMPLETE roadmap JSON again.

Correct the validation problem while preserving all roadmap requirements.

Important:

- Every choice node must contain exactly one recommended=true alternative.
- Every other alternative in that choice node must have recommended=false.
- A recommended alternative must contain a non-empty recommendation_reason.
- A standard node must not contain choices.
- A node containing choices must have type="choice".
- Node IDs must remain valid and consecutive.
- Order values must remain valid and consecutive.
- Prerequisites must reference earlier existing nodes only.
- If this is a child roadmap, every choice must have separate_roadmap=false.
- If this is conservative revision mode, do not remove existing correct
  nodes or choices unless the manager feedback explicitly requires removal.
- If an existing roadmap draft is supplied for choice finalization,
  preserve its complete structure and only finalize recommended and
  recommendation_reason.
- Return only the complete valid JSON object.
""",
                }
            )

        try:
            result = llm.invoke(
                messages
            )

            parsed_output = (
                extract_json_object(
                    result.content
                )
            )

            parsed_output[
                "roadmap_topic"
            ] = topic

            parsed_output[
                "title"
            ] = topic

            parsed_output = (
                normalize_roadmap_node_types(
                    parsed_output
                )
            )

            if is_child_roadmap:
                parsed_output = (
                    enforce_child_depth_limit(
                        parsed_output
                    )
                )

            validated_result = (
                RoadmapAgentResult
                .model_validate(
                    parsed_output
                )
                .model_dump()
            )

            validate_no_generic_support_nodes(
                roadmap_topic=topic,
                roadmap=validated_result,
            )

            if revision_mode:
                validate_conservative_revision(
                    existing_roadmap=(
                        existing_roadmap
                    ),
                    revised_roadmap=(
                        validated_result
                    ),
                    revision_feedback=(
                        revision_feedback
                    ),
                )

                return validated_result

            if existing_roadmap is None:
                return validated_result

            preserved_roadmap = deepcopy(
                existing_roadmap
            )

            final_choice_lookup: dict[
                tuple[int, int],
                dict[str, Any],
            ] = {}

            for node in validated_result[
                "nodes"
            ]:
                if node[
                    "type"
                ] != "choice":
                    continue

                for choice in node[
                    "choices"
                ]:
                    key = (
                        node[
                            "node_id"
                        ],
                        choice[
                            "choice_id"
                        ],
                    )

                    final_choice_lookup[
                        key
                    ] = choice

            for node in preserved_roadmap[
                "nodes"
            ]:
                if node[
                    "type"
                ] != "choice":
                    continue

                for choice in node[
                    "choices"
                ]:
                    key = (
                        node[
                            "node_id"
                        ],
                        choice[
                            "choice_id"
                        ],
                    )

                    if key not in final_choice_lookup:
                        raise ValueError(
                            "Finalized roadmap changed an existing choice structure."
                        )

                    finalized_choice = (
                        final_choice_lookup[
                            key
                        ]
                    )

                    choice[
                        "recommended"
                    ] = finalized_choice[
                        "recommended"
                    ]

                    choice[
                        "recommendation_reason"
                    ] = finalized_choice[
                        "recommendation_reason"
                    ]

            if is_child_roadmap:
                preserved_roadmap = (
                    enforce_child_depth_limit(
                        preserved_roadmap
                    )
                )

            final_result = (
                RoadmapAgentResult
                .model_validate(
                    preserved_roadmap
                )
                .model_dump()
            )

            validate_no_generic_support_nodes(
                roadmap_topic=topic,
                roadmap=final_result,
            )

            return final_result

        except Exception as error:
            last_error = error

    raise RuntimeError(
        "Roadmap Builder Agent failed "
        f"after {maximum_attempts} attempts: "
        f"{last_error}"
    ) from last_error

if __name__ == "__main__":
    from roadmap_workflow.tools.choice_demand_research import (
        research_choice_demand,
    )

    from roadmap_workflow.tools.current_domain_research import (
        research_current_domain,
    )

    test_topic = (
        "Machine Learning Engineering"
    )

    research = (
        research_current_domain(
            test_topic
        )
    )

    draft_roadmap = (
        run_roadmap_builder_agent(
            roadmap_topic=(
                test_topic
            ),
            current_domain_research=(
                research
            ),
        )
    )

    choice_research_summaries: list[
        str
    ] = []

    for node in draft_roadmap[
        "nodes"
    ]:
        if (
            node["type"]
            != "choice"
        ):
            continue

        choice_titles = [
            choice["title"]
            for choice in node[
                "choices"
            ]
        ]

        choice_research = (
            research_choice_demand(
                roadmap_topic=(
                    test_topic
                ),
                choice_node_title=(
                    node["title"]
                ),
                choices=(
                    choice_titles
                ),
            )
        )

        choice_research_summaries.append(
            choice_research[
                "summary"
            ]
        )

    if choice_research_summaries:
        final_roadmap = (
            run_roadmap_builder_agent(
                roadmap_topic=(
                    test_topic
                ),
                current_domain_research=(
                    research
                ),
                choice_demand_research=(
                    "\n\n".join(
                        choice_research_summaries
                    )
                ),
                existing_roadmap=(
                    draft_roadmap
                ),
            )
        )

    else:
        final_roadmap = (
            draft_roadmap
        )

    print(
        json.dumps(
            final_roadmap,
            indent=2,
            ensure_ascii=False,
        )
    )