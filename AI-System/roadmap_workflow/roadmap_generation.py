import json
from typing import Any

from roadmap_workflow.agents.roadmap_builder_agent import (
    run_roadmap_builder_agent,
)
from roadmap_workflow.tools.choice_demand_research import (
    research_choice_demand,
)
from roadmap_workflow.tools.current_domain_research import (
    research_current_domain,
)


def collect_completed_parent_competencies(
    parent_roadmap: dict[str, Any],
    choice_node: dict[str, Any],
) -> list[str]:
    node_lookup = {
        node["node_id"]: node
        for node in parent_roadmap["nodes"]
    }

    completed_ids: set[int] = set()

    pending_ids = list(
        choice_node[
            "prerequisite_node_ids"
        ]
    )

    while pending_ids:
        node_id = pending_ids.pop()

        if node_id in completed_ids:
            continue

        completed_ids.add(
            node_id
        )

        prerequisite_node = (
            node_lookup[
                node_id
            ]
        )

        pending_ids.extend(
            prerequisite_node[
                "prerequisite_node_ids"
            ]
        )

    completed_nodes = [
        node_lookup[node_id]
        for node_id in completed_ids
    ]

    completed_nodes.sort(
        key=lambda node: node["order"]
    )

    return [
        node["title"]
        for node in completed_nodes
    ]


def finalize_roadmap_choices(
    roadmap_topic: str,
    current_domain_research: dict[str, Any],
    draft_roadmap: dict[str, Any],
    completed_parent_competencies: list[str] | None = None,
    parent_roadmap_context: dict[str, Any] | None = None,
) -> dict[str, Any]:
    choice_research_summaries: list[str] = []

    for node in draft_roadmap["nodes"]:
        if node["type"] != "choice":
            continue

        choice_titles = [
            choice["title"]
            for choice in node["choices"]
        ]

        choice_research = (
            research_choice_demand(
                roadmap_topic=roadmap_topic,
                choice_node_title=node["title"],
                choices=choice_titles,
            )
        )

        choice_research_summaries.append(
            choice_research["summary"]
        )

    if not choice_research_summaries:
        return draft_roadmap

    return run_roadmap_builder_agent(
        roadmap_topic=roadmap_topic,
        current_domain_research=(
            current_domain_research
        ),
        choice_demand_research=(
            "\n\n".join(
                choice_research_summaries
            )
        ),
        existing_roadmap=draft_roadmap,
        completed_parent_competencies=(
            completed_parent_competencies
        ),
        parent_roadmap_context=(
            parent_roadmap_context
        ),
    )


def generate_final_roadmap(
    roadmap_topic: str,
    research_topic: str | None = None,
    completed_parent_competencies: list[str] | None = None,
    parent_roadmap_context: dict[str, Any] | None = None,
) -> dict[str, Any]:
    topic = roadmap_topic.strip()

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    research_query_topic = (
        research_topic.strip()
        if research_topic
        else topic
    )

    current_research = (
        research_current_domain(
            research_query_topic
        )
    )

    draft_roadmap = (
        run_roadmap_builder_agent(
            roadmap_topic=topic,
            current_domain_research=(
                current_research
            ),
            completed_parent_competencies=(
                completed_parent_competencies
            ),
            parent_roadmap_context=(
                parent_roadmap_context
            ),
        )
    )

    return finalize_roadmap_choices(
        roadmap_topic=topic,
        current_domain_research=(
            current_research
        ),
        draft_roadmap=draft_roadmap,
        completed_parent_competencies=(
            completed_parent_competencies
        ),
        parent_roadmap_context=(
            parent_roadmap_context
        ),
    )


def revise_final_roadmap(
    existing_roadmap: dict[str, Any],
    regeneration_feedback: str,
    research_topic: str | None = None,
    completed_parent_competencies: list[str] | None = None,
    parent_roadmap_context: dict[str, Any] | None = None,
) -> dict[str, Any]:
    feedback = (
        regeneration_feedback.strip()
    )

    if not feedback:
        raise ValueError(
            "regeneration_feedback is required."
        )

    roadmap_topic = str(
        existing_roadmap.get(
            "roadmap_topic",
            "",
        )
    ).strip()

    if not roadmap_topic:
        raise ValueError(
            "Existing roadmap is missing roadmap_topic."
        )

    research_query_topic = (
        research_topic.strip()
        if research_topic
        else roadmap_topic
    )

    current_research = (
        research_current_domain(
            research_query_topic
        )
    )

    revised_draft = (
        run_roadmap_builder_agent(
            roadmap_topic=roadmap_topic,
            current_domain_research=(
                current_research
            ),
            existing_roadmap=(
                existing_roadmap
            ),
            completed_parent_competencies=(
                completed_parent_competencies
            ),
            parent_roadmap_context=(
                parent_roadmap_context
            ),
            revision_feedback=feedback,
        )
    )

    return finalize_roadmap_choices(
        roadmap_topic=roadmap_topic,
        current_domain_research=(
            current_research
        ),
        draft_roadmap=revised_draft,
        completed_parent_competencies=(
            completed_parent_competencies
        ),
        parent_roadmap_context=(
            parent_roadmap_context
        ),
    )


def generate_child_roadmaps(
    parent_roadmap: dict[str, Any],
) -> list[dict[str, Any]]:
    parent_topic = (
        parent_roadmap[
            "roadmap_topic"
        ]
    )

    child_roadmaps: list[
        dict[str, Any]
    ] = []

    for node in parent_roadmap["nodes"]:
        if node["type"] != "choice":
            continue

        completed_parent_competencies = (
            collect_completed_parent_competencies(
                parent_roadmap=(
                    parent_roadmap
                ),
                choice_node=node,
            )
        )

        for choice in node["choices"]:
            if not choice[
                "separate_roadmap"
            ]:
                continue

            choice_title = (
                choice["title"]
            )

            child_research_topic = (
                f"{choice_title} "
                f"for {parent_topic}"
            )

            child_roadmap = (
                generate_final_roadmap(
                    roadmap_topic=(
                        choice_title
                    ),
                    research_topic=(
                        child_research_topic
                    ),
                    completed_parent_competencies=(
                        completed_parent_competencies
                    ),
                    parent_roadmap_context=(
                        parent_roadmap
                    ),
                )
            )

            child_roadmaps.append(
                {
                    "parent_node_id": (
                        node["node_id"]
                    ),
                    "choice_id": (
                        choice["choice_id"]
                    ),
                    "choice_title": (
                        choice_title
                    ),
                    "roadmap_result": (
                        child_roadmap
                    ),
                }
            )

    return child_roadmaps


def normalize_title(
    value: str,
) -> str:
    return " ".join(
        value.casefold().split()
    )


def feedback_targets_child(
    feedback: str,
    choice_title: str,
) -> bool:
    normalized_feedback = (
        normalize_title(
            feedback
        )
    )

    normalized_choice_title = (
        normalize_title(
            choice_title
        )
    )

    return (
        normalized_choice_title
        in normalized_feedback
    )


def build_existing_child_lookup(
    child_roadmaps: list[
        dict[str, Any]
    ],
) -> dict[str, dict[str, Any]]:
    child_lookup: dict[
        str,
        dict[str, Any],
    ] = {}

    for child in child_roadmaps:
        choice_title = str(
            child.get(
                "choice_title",
                "",
            )
        ).strip()

        if not choice_title:
            continue

        normalized_choice_title = (
            normalize_title(
                choice_title
            )
        )

        if (
            normalized_choice_title
            in child_lookup
        ):
            raise ValueError(
                "Duplicate existing child roadmap choice title."
            )

        child_lookup[
            normalized_choice_title
        ] = child

    return child_lookup


def reconcile_child_roadmaps(
    revised_parent: dict[str, Any],
    existing_children: list[
        dict[str, Any]
    ],
    regeneration_feedback: str,
) -> list[dict[str, Any]]:
    parent_topic = (
        revised_parent[
            "roadmap_topic"
        ]
    )

    existing_child_lookup = (
        build_existing_child_lookup(
            existing_children
        )
    )

    reconciled_children: list[
        dict[str, Any]
    ] = []

    for node in revised_parent["nodes"]:
        if node["type"] != "choice":
            continue

        completed_parent_competencies = (
            collect_completed_parent_competencies(
                parent_roadmap=(
                    revised_parent
                ),
                choice_node=node,
            )
        )

        for choice in node["choices"]:
            if not choice[
                "separate_roadmap"
            ]:
                continue

            choice_title = (
                choice["title"]
            )

            normalized_choice_title = (
                normalize_title(
                    choice_title
                )
            )

            existing_child = (
                existing_child_lookup.get(
                    normalized_choice_title
                )
            )

            child_research_topic = (
                f"{choice_title} "
                f"for {parent_topic}"
            )

            if existing_child is None:
                child_roadmap = (
                    generate_final_roadmap(
                        roadmap_topic=(
                            choice_title
                        ),
                        research_topic=(
                            child_research_topic
                        ),
                        completed_parent_competencies=(
                            completed_parent_competencies
                        ),
                        parent_roadmap_context=(
                            revised_parent
                        ),
                    )
                )

            elif feedback_targets_child(
                feedback=(
                    regeneration_feedback
                ),
                choice_title=(
                    choice_title
                ),
            ):
                child_roadmap = (
                    revise_final_roadmap(
                        existing_roadmap=(
                            existing_child[
                                "roadmap_result"
                            ]
                        ),
                        regeneration_feedback=(
                            regeneration_feedback
                        ),
                        research_topic=(
                            child_research_topic
                        ),
                        completed_parent_competencies=(
                            completed_parent_competencies
                        ),
                        parent_roadmap_context=(
                            revised_parent
                        ),
                    )
                )

            else:
                child_roadmap = (
                    existing_child[
                        "roadmap_result"
                    ]
                )

            reconciled_children.append(
                {
                    "parent_node_id": (
                        node["node_id"]
                    ),
                    "choice_id": (
                        choice["choice_id"]
                    ),
                    "choice_title": (
                        choice_title
                    ),
                    "roadmap_result": (
                        child_roadmap
                    ),
                }
            )

    return reconciled_children


def generate_roadmap_package(
    roadmap_topic: str,
) -> dict[str, Any]:
    parent_roadmap = (
        generate_final_roadmap(
            roadmap_topic=(
                roadmap_topic
            )
        )
    )

    child_roadmaps = (
        generate_child_roadmaps(
            parent_roadmap=(
                parent_roadmap
            )
        )
    )

    return {
        "roadmap_result": (
            parent_roadmap
        ),
        "child_roadmaps": (
            child_roadmaps
        ),
    }


def regenerate_roadmap_package(
    existing_package: dict[
        str,
        Any,
    ],
    regeneration_feedback: str,
) -> dict[str, Any]:
    if not isinstance(
        existing_package,
        dict,
    ):
        raise ValueError(
            "existing_package must be an object."
        )

    existing_parent = (
        existing_package.get(
            "roadmap_result"
        )
    )

    existing_children = (
        existing_package.get(
            "child_roadmaps",
            [],
        )
    )

    if not isinstance(
        existing_parent,
        dict,
    ):
        raise ValueError(
            "Existing package is missing roadmap_result."
        )

    if not isinstance(
        existing_children,
        list,
    ):
        raise ValueError(
            "Existing package child_roadmaps must be a list."
        )

    feedback = (
        regeneration_feedback.strip()
    )

    if not feedback:
        raise ValueError(
            "regeneration_feedback is required."
        )

    revised_parent = (
        revise_final_roadmap(
            existing_roadmap=(
                existing_parent
            ),
            regeneration_feedback=(
                feedback
            ),
        )
    )

    revised_children = (
        reconcile_child_roadmaps(
            revised_parent=(
                revised_parent
            ),
            existing_children=(
                existing_children
            ),
            regeneration_feedback=(
                feedback
            ),
        )
    )

    return {
        "roadmap_result": (
            revised_parent
        ),
        "child_roadmaps": (
            revised_children
        ),
    }


if __name__ == "__main__":
    test_topic = (
        "Machine Learning Engineering"
    )

    result = (
        generate_roadmap_package(
            test_topic
        )
    )

    print(
        json.dumps(
            result,
            indent=2,
            ensure_ascii=False,
        )
    )