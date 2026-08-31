import re
from datetime import datetime, timezone
from typing import Any

from roadmap_workflow.tools.current_domain_research import (
    enrich_evidence,
    get_hostname,
    normalize_url_for_comparison,
    remove_duplicate_results,
    search_web,
    select_evidence,
)


MAX_CHOICE_EVIDENCE = 4
CHOICE_CANDIDATE_LIMIT = 8


def normalize_comparison_text(
    text: str,
) -> str:
    return re.sub(
        r"[^a-z0-9+#]+",
        "",
        text.casefold(),
    )


def text_mentions_choice(
    text: str,
    choice_title: str,
) -> bool:
    normalized_text = (
        normalize_comparison_text(
            text
        )
    )

    normalized_choice = (
        normalize_comparison_text(
            choice_title
        )
    )

    return (
        normalized_choice
        in normalized_text
    )


def count_choices_mentioned(
    text: str,
    choices: list[str],
) -> int:
    return sum(
        1
        for choice in choices
        if text_mentions_choice(
            text,
            choice,
        )
    )


def build_choice_demand_queries(
    roadmap_topic: str,
    choice_node_title: str,
    choices: list[str],
) -> list[
    tuple[
        str,
        str,
    ]
]:
    current_year = datetime.now(
        timezone.utc
    ).year

    previous_year = (
        current_year - 1
    )

    choice_text = " ".join(
        choices
    )

    comparison_text = " vs ".join(
        choices
    )

    return [
        (
            "job_market",
            (
                f"{roadmap_topic} "
                f"{choice_node_title} "
                f"{choice_text} "
                "employer demand jobs hiring "
                f"{current_year}"
            ),
        ),
        (
            "job_market",
            (
                f"{comparison_text} "
                "jobs hiring employer demand "
                f"{current_year}"
            ),
        ),
        (
            "survey_report",
            (
                f"{roadmap_topic} "
                f"{choice_text} "
                "developer survey professional "
                "adoption "
                f"{previous_year} {current_year}"
            ),
        ),
    ]


def build_choice_demand_summary(
    roadmap_topic: str,
    choice_node_title: str,
    choices: list[str],
    evidence: list[
        dict[str, Any]
    ],
) -> str:
    lines = [
        (
            "CHOICE-DEMAND RESEARCH FOR: "
            f"{roadmap_topic}"
        ),
        (
            "CHOICE STAGE: "
            f"{choice_node_title}"
        ),
        (
            "ALTERNATIVES: "
            + ", ".join(
                choices
            )
        ),
        "",
        (
            "PURPOSE: Help select one recommended "
            "alternative for current professional "
            "learning."
        ),
        "",
        (
            "PRIMARY FACTOR: Current employer "
            "and company demand."
        ),
        (
            "SECONDARY FACTOR: Current professional "
            "adoption when employer-demand evidence "
            "does not clearly distinguish the choices."
        ),
        "",
        (
            "IMPORTANT CLAIM RULE: Do not invent "
            "job-posting counts, percentages, market "
            "share, rankings, or quantitative "
            "advantages."
        ),
        (
            "Do not say an alternative 'dominates', "
            "'leads the market', has the 'highest "
            "demand', or is the 'most requested' "
            "unless the supplied evidence explicitly "
            "establishes that exact comparison."
        ),
        (
            "When exact comparative evidence is not "
            "available, use careful qualitative "
            "wording such as strong employer demand "
            "or broad professional adoption."
        ),
    ]

    if not evidence:
        lines.extend(
            [
                "",
                (
                    "No sufficiently strong comparative "
                    "choice-demand evidence was retrieved."
                ),
                (
                    "Choose conservatively using "
                    "established professional adoption "
                    "without inventing market claims."
                ),
            ]
        )

        return "\n".join(
            lines
        )

    for index, item in enumerate(
        evidence,
        start=1,
    ):
        reasons = "; ".join(
            item[
                "quality_reasons"
            ]
        )

        lines.extend(
            [
                "",
                "=" * 60,
                f"Evidence {index}",
                (
                    "Source: "
                    f"{item['source_name']}"
                ),
                (
                    "Source type: "
                    f"{item['source_type']}"
                ),
                (
                    "Quality: "
                    f"{item['source_quality']}"
                ),
                (
                    "Choices mentioned: "
                    f"{item['choices_mentioned']}"
                ),
                (
                    "Quality reasons: "
                    f"{reasons}"
                ),
                (
                    "Title: "
                    f"{item['title']}"
                ),
                (
                    "URL: "
                    f"{item['url']}"
                ),
                "Evidence text:",
                item[
                    "evidence_text"
                ],
            ]
        )

    return "\n".join(
        lines
    )


def research_choice_demand(
    roadmap_topic: str,
    choice_node_title: str,
    choices: list[str],
) -> dict[str, Any]:
    topic = roadmap_topic.strip()

    node_title = (
        choice_node_title.strip()
    )

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    if not node_title:
        raise ValueError(
            "choice_node_title is required."
        )

    cleaned_choices = [
        choice.strip()
        for choice in choices
        if choice.strip()
    ]

    cleaned_choices = list(
        dict.fromkeys(
            cleaned_choices
        )
    )

    if len(
        cleaned_choices
    ) < 2:
        raise ValueError(
            (
                "At least two distinct "
                "choices are required."
            )
        )

    all_results: list[
        dict[str, Any]
    ] = []

    search_warnings: list[
        str
    ] = []

    for (
        source_type,
        query,
    ) in build_choice_demand_queries(
        roadmap_topic=topic,
        choice_node_title=(
            node_title
        ),
        choices=cleaned_choices,
    ):
        try:
            all_results.extend(
                search_web(
                    query=query,
                    category="market",
                    evidence_role="market",
                    requested_source_type=(
                        source_type
                    ),
                )
            )

        except RuntimeError as error:
            search_warnings.append(
                str(error)
            )

    unique_results = (
        remove_duplicate_results(
            all_results
        )
    )

    candidates = (
        select_evidence(
            results=(
                unique_results
            ),
            category="market",
            evidence_role="market",
            limit=(
                CHOICE_CANDIDATE_LIMIT
            ),
        )
    )

    relevance_topic = (
        f"{topic} "
        + " ".join(
            cleaned_choices
        )
    )

    enriched_evidence = (
        enrich_evidence(
            roadmap_topic=(
                relevance_topic
            ),
            evidence_items=(
                candidates
            ),
        )
    )

    comparative_evidence: list[
        dict[str, Any]
    ] = []

    for item in enriched_evidence:
        choices_mentioned = (
            count_choices_mentioned(
                text=(
                    item[
                        "evidence_text"
                    ]
                ),
                choices=(
                    cleaned_choices
                ),
            )
        )

        if (
            choices_mentioned
            == 0
        ):
            continue

        comparative_evidence.append(
            {
                **item,
                "choices_mentioned": (
                    choices_mentioned
                ),
            }
        )

    comparative_evidence.sort(
        key=lambda item: (
            -item[
                "choices_mentioned"
            ],
            -item[
                "source_score"
            ],
            get_hostname(
                item["url"]
            ),
            normalize_url_for_comparison(
                item["url"]
            ),
        )
    )

    evidence = (
        comparative_evidence[
            :MAX_CHOICE_EVIDENCE
        ]
    )

    summary = (
        build_choice_demand_summary(
            roadmap_topic=topic,
            choice_node_title=(
                node_title
            ),
            choices=(
                cleaned_choices
            ),
            evidence=evidence,
        )
    )

    sources: list[
        str
    ] = []

    seen_sources: set[
        str
    ] = set()

    for item in evidence:
        url = item[
            "url"
        ]

        normalized_url = (
            normalize_url_for_comparison(
                url
            )
        )

        if (
            normalized_url
            in seen_sources
        ):
            continue

        seen_sources.add(
            normalized_url
        )

        sources.append(
            url
        )

    return {
        "roadmap_topic": topic,
        "choice_node_title": (
            node_title
        ),
        "choices": (
            cleaned_choices
        ),
        "evidence": evidence,
        "summary": summary,
        "sources": sources,
        "search_warnings": (
            search_warnings
        ),
    }


if __name__ == "__main__":
    result = (
        research_choice_demand(
            roadmap_topic=(
                "Frontend Development"
            ),
            choice_node_title=(
                "Frontend Framework"
            ),
            choices=[
                "React",
                "Vue",
                "Angular",
                "Svelte",
            ],
        )
    )

    print(
        result["summary"]
    )