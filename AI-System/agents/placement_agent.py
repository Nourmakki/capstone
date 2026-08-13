import json
import re
from collections import defaultdict, deque
from typing import Any
from .llm import llm

def extract_json_object(content: Any) -> dict[str, Any]:
    """
    Extract a JSON object from an LLM response.

    The model may return:
    - a dictionary
    - a plain string
    - a list of content blocks
    - JSON wrapped inside Markdown fences
    """

    if isinstance(content, dict):
        return content

    if isinstance(content, list):
        text_parts: list[str] = []

        for item in content:
            if isinstance(item, str):
                text_parts.append(item)

            elif isinstance(item, dict):
                if "text" in item:
                    text_parts.append(str(item["text"]))

                elif "content" in item:
                    text_parts.append(str(item["content"]))

                else:
                    text_parts.append(
                        json.dumps(
                            item,
                            ensure_ascii=False,
                        )
                    )

            else:
                text_parts.append(str(item))

        text = "\n".join(text_parts)

    else:
        text = str(content)

    text = text.strip()

    # Remove optional Markdown fences.
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
        parsed = json.loads(text)

        if not isinstance(parsed, dict):
            raise ValueError(
                "Placement Agent output must be a JSON object."
            )

        return parsed

    except json.JSONDecodeError:
        pass

    # Fallback if the model added text before or after the JSON.
    match = re.search(
        r"\{.*\}",
        text,
        re.DOTALL,
    )

    if not match:
        raise ValueError(
            "No valid JSON object found in Placement Agent response: "
            f"{text}"
        )

    parsed = json.loads(match.group(0))

    if not isinstance(parsed, dict):
        raise ValueError(
            "Placement Agent output must be a JSON object."
        )

    return parsed


# =====================================================================
# TEMPORARY NODE IDS
# =====================================================================

def assign_temporary_node_ids(existing_nodes: list[dict[str, Any]],
suggested_nodes: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    """
    Assign temporary numeric IDs to suggested nodes.
    Example:
    Existing IDs:
        1, 2, 3

    Suggested temporary IDs:
        4, 5, 6
    These IDs are used only inside the AI workflow.
    They are not database IDs.
    """

    maximum_existing_id = max(
        (
            node["id"]
            for node in existing_nodes
        ),
        default=0,
    )

    prepared_suggestions: list[dict[str, Any]] = []

    for index, suggested_node in enumerate(
        suggested_nodes,
        start=1,
    ):
        if not isinstance(suggested_node, dict):
            raise ValueError(
                f"Suggested node at index {index - 1} "
                "must be an object."
            )

        title = str(
            suggested_node.get("title", "")
        ).strip()

        description = str(
            suggested_node.get("description", "")
        ).strip()

        difficulty = str(
            suggested_node.get("difficulty", "")
        ).strip().lower()

        if not title:
            raise ValueError(
                f"Suggested node at index {index - 1} "
                "has no valid title."
            )

        if not description:
            raise ValueError(
                f"Suggested node '{title}' has no description."
            )

        if difficulty not in {
            "beginner",
            "intermediate",
            "advanced",
        }:
            raise ValueError(
                f"Suggested node '{title}' has invalid difficulty: "
                f"{difficulty}"
            )

        prepared_suggestions.append(
            {
                **suggested_node,
                "id": maximum_existing_id + index,
                "title": title,
                "description": description,
                "difficulty": difficulty,
            }
        )

    return prepared_suggestions


# =====================================================================
# CYCLE CHECK
# =====================================================================

def graph_has_cycle(node_ids: set[int],edges: set[tuple[int, int]],) -> bool:
    """
    Return True when the proposed roadmap contains a directed cycle.

    Valid:
        1 -> 2 -> 3

    Invalid:
        1 -> 2 -> 3 -> 1
    """

    adjacency: dict[int, list[int]] = defaultdict(list)

    indegree = {
        node_id: 0
        for node_id in node_ids
    }

    for from_node_id, to_node_id in edges:
        adjacency[from_node_id].append(to_node_id)
        indegree[to_node_id] += 1

    queue = deque(
        node_id
        for node_id, degree in indegree.items()
        if degree == 0
    )

    visited_count = 0

    while queue:
        current_node_id = queue.popleft()
        visited_count += 1

        for successor_id in adjacency[current_node_id]:
            indegree[successor_id] -= 1

            if indegree[successor_id] == 0:
                queue.append(successor_id)

    return visited_count != len(node_ids)


# =====================================================================
# PLACEMENT AGENT PROMPT
# =====================================================================

PLACEMENT_AGENT_PROMPT = """
You are a Placement Agent for a Computer Science learning platform.

Your responsibility is ONLY to determine where newly suggested nodes should
be placed inside an existing directed learning roadmap.

You do not create database records.
You do not modify the roadmap.
You only propose placements for later admin review.

INPUT

You receive:
- roadmap topic
- existing roadmap nodes
- existing directed edges
- suggested roadmap nodes with temporary numeric IDs

Existing nodes and suggested nodes use the same numeric ID format during this
workflow.

EDGE MEANING
An edge:

from_node_id -> to_node_id

means that from_node_id should be studied before to_node_id.

Example:

1 -> 2 -> 3

means:

node 1 is studied before node 2,
and node 2 is studied before node 3.

CORE OBJECTIVE

Determine the correct placement for every suggested node.

For every suggested node, return:

- direct_prerequisite_node_ids
- direct_successor_node_ids
- recommended_predecessor_node_ids
- explanation

DIRECT PREREQUISITES

direct_prerequisite_node_ids contains only nodes that must be learned
immediately before the suggested node.
Return direct prerequisites only.
Do not return every earlier node.

When one candidate prerequisite is already an ancestor of another selected
prerequisite, return only the closest prerequisite and omit the earlier
ancestor.

DIRECT SUCCESSORS

direct_successor_node_ids contains only nodes that should directly follow
the suggested node.
A suggested node may open multiple learning branches.

DEPENDENCY DIRECTION

A node is a direct successor only when it requires knowledge introduced by
the suggested node.

Do not make a node a direct successor merely because the suggested topic
improves, secures, automates, monitors, manages, tests, or is commonly used
with that node.

When a suggested topic operates on, manages, secures, monitors, or tests an
existing technology, the existing technology is usually a prerequisite or
learning context for the suggested topic, not its successor.

Cross-cutting practices should normally form a separate branch from their
closest mandatory foundation.

When no existing node genuinely depends on the suggested node, return an
empty direct_successor_node_ids list.

Example:

4 Prompt Engineering -> 5 RAG
4 Prompt Engineering -> 6 AI Agents

Prompt Engineering should return:

"direct_successor_node_ids": [5, 6]

RAG should return:

"direct_prerequisite_node_ids": [4]

AI Agents should return:

"direct_prerequisite_node_ids": [4]

RELATIONSHIP CONSISTENCY

When suggested node A lists suggested node B as a direct successor,
node B must list node A as a direct prerequisite.

Example:

If node 4 contains:

"direct_successor_node_ids": [5]

then node 5 must contain:

"direct_prerequisite_node_ids": [4]

RECOMMENDED PREDECESSORS

recommended_predecessor_node_ids contains topics that are useful to study
before the suggested node but are not mandatory prerequisites.

Do not return a recommended predecessor when that node is already an
ancestor of the suggested node through hard prerequisite edges.

This field does not create a graph edge.

Use it only for helpful learning order.

MANDATORY VERSUS RECOMMENDED

Create a direct prerequisite only when the later topic genuinely depends on
the earlier topic.

A topic is not a mandatory prerequisite merely because:

- it is commonly used with the suggested node
- it is helpful
- it belongs to the same field
- it is normally taught nearby
- it has a lower difficulty
- studying it first may improve understanding

Use recommended_predecessor_node_ids for useful but optional ordering.

INSERTING A NODE BETWEEN EXISTING NODES

A suggested node may need to be inserted between two existing nodes.

Insert a suggested node between existing nodes A and B only when B genuinely
requires knowledge taught by the suggested node. If B can be learned without
it, preserve A -> B and create a separate branch.

Example existing graph:

2 -> 3

New placement:

2 -> 4 -> 3

Then:

- node 4 has prerequisite [2]
- node 4 has successor [3]
- the existing edge 2 -> 3 must be returned inside
  existing_edges_to_remove

Do not remove an existing edge unless the new placement replaces it.

PLACEMENT ACCURACY

Determine placement using:

- conceptual prerequisites
- programming prerequisites
- mathematical prerequisites
- practical implementation dependencies
- architecture dependencies
- security dependencies
- infrastructure dependencies
- the existing roadmap sequence
- node titles
- node descriptions
- node difficulty
- educational learning progression

Think about all suggested nodes together before producing the result.

Do not place each suggested node independently without considering its
relationship with the other suggestions.

Difficulty can support placement reasoning, but difficulty alone does not
determine placement.

A node is not automatically placed last because it is advanced.

BRANCHING EXAMPLE

Existing roadmap:

1 Python -> 2 Machine Learning -> 3 Deep Learning

Suggested nodes:

4 Prompt Engineering
5 Retrieval-Augmented Generation
6 AI Agents

Correct hard placement:

3 -> 4
4 -> 5
4 -> 6

This creates two branches:

3 -> 4 -> 5
       |
       -> 6

RAG may be recommended before AI Agents, but it is not a mandatory
prerequisite.

Therefore:

Prompt Engineering:
- prerequisites: [3]
- successors: [5, 6]

RAG:
- prerequisites: [4]
- successors: []

AI Agents:
- prerequisites: [4]
- successors: []
- recommended predecessors: [5]

Do not create the hard edge:

5 -> 6

unless the supplied descriptions clearly establish that RAG is mandatory
for the specific AI Agents topic.

GRAPH RULES

You must:

- use only supplied node IDs
- return only immediate prerequisites and successors
- avoid self-references
- avoid duplicate IDs
- avoid cycles
- preserve suggested-node titles exactly
- return one placement result for every suggested node
- leave lists empty when no valid relationship exists

Do not invent relationships merely to avoid empty lists.

EXPLANATION

Write one concise explanation for every suggested node.

The explanation should state:

- why the prerequisite is appropriate
- why its successors are appropriate
- why a recommended predecessor is useful but optional, when applicable

Do not mention:

- sources
- confidence scores
- the LLM
- admin approval

OUTPUT RESTRICTIONS

You must NOT:

- invent nodes
- invent node IDs
- rename suggested nodes
- modify node descriptions
- modify difficulty
- include explanations outside JSON
- add fields outside the required structure

FINAL SELF-CHECK

Before returning the result, verify:

1. Every suggested node appears exactly once.
2. Every referenced ID exists in the input.
3. No node references itself.
4. Only immediate prerequisites and successors are returned.
5. Relationships between suggested nodes are consistent.
6. Recommended predecessors are not also direct prerequisites.
7. Existing edges are removed only when a suggested node replaces them.
8. No cycle is created.
9. Suggested titles remain unchanged.
10. The response contains only valid JSON.

OUTPUT FORMAT

Return ONLY valid JSON.

Do not:
- wrap the JSON in Markdown
- add text before or after the JSON
- add comments
- add trailing commas
- add additional fields

Return exactly:

{
  "placement_suggestions": [
    {
      "suggested_node_id": 4,
      "suggested_node_title": "Suggested node title",
      "direct_prerequisite_node_ids": [3],
      "direct_successor_node_ids": [5, 6],
      "recommended_predecessor_node_ids": [],
      "explanation": "Concise placement explanation."
    }
  ],
  "existing_edges_to_remove": []
}

When an existing edge must be removed:

{
  "placement_suggestions": [
    {
      "suggested_node_id": 4,
      "suggested_node_title": "Suggested node title",
      "direct_prerequisite_node_ids": [2],
      "direct_successor_node_ids": [3],
      "recommended_predecessor_node_ids": [],
      "explanation": "The suggested node is placed between nodes 2 and 3."
    }
  ],
  "existing_edges_to_remove": [
    {
      "from_node_id": 2,
      "to_node_id": 3
    }
  ]
}
"""


# =====================================================================
# OUTPUT VALIDATION
# =====================================================================

def validate_placement_output(
    parsed_output: dict[str, Any],
    existing_nodes: list[dict[str, Any]],
    existing_edges: list[dict[str, Any]],
    suggested_nodes: list[dict[str, Any]],
) -> dict[str, Any]:
    """
    Perform only the placement checks that are important for this workflow.

    Checks:
    - one result for every suggested node
    - valid IDs
    - exact suggested-node titles
    - no self-reference
    - no duplicated IDs
    - consistent suggested-node relationships
    - valid existing edges to remove
    - no cycle
    """

    required_top_level_fields = {
        "placement_suggestions",
        "existing_edges_to_remove",
    }

    if set(parsed_output.keys()) != required_top_level_fields:
        raise ValueError(
            "Placement output must contain only: "
            "placement_suggestions and existing_edges_to_remove."
        )

    placement_suggestions = parsed_output[
        "placement_suggestions"
    ]

    edges_to_remove = parsed_output[
        "existing_edges_to_remove"
    ]

    if not isinstance(placement_suggestions, list):
        raise ValueError(
            "placement_suggestions must be a list."
        )

    if not isinstance(edges_to_remove, list):
        raise ValueError(
            "existing_edges_to_remove must be a list."
        )

    existing_node_ids = {
        node["id"]
        for node in existing_nodes
    }

    suggested_nodes_by_id = {
        node["id"]: node
        for node in suggested_nodes
    }

    suggested_node_ids = set(
        suggested_nodes_by_id
    )

    all_node_ids = (
        existing_node_ids
        | suggested_node_ids
    )

    if len(placement_suggestions) != len(suggested_nodes):
        raise ValueError(
            "The number of placement suggestions must equal the "
            "number of suggested nodes. "
            f"Expected {len(suggested_nodes)}, "
            f"received {len(placement_suggestions)}."
        )

    required_placement_fields = {
        "suggested_node_id",
        "suggested_node_title",
        "direct_prerequisite_node_ids",
        "direct_successor_node_ids",
        "recommended_predecessor_node_ids",
        "explanation",
    }

    placements_by_id: dict[int, dict[str, Any]] = {}
    cleaned_placements: list[dict[str, Any]] = []

    def validate_relationship_ids(
        value: Any,
        field_name: str,
        owner_id: int,
    ) -> list[int]:
        """
        Validate one prerequisite, successor, or recommendation list.
        """

        if not isinstance(value, list):
            raise ValueError(
                f"{field_name} for node {owner_id} must be a list."
            )

        cleaned_ids: list[int] = []

        for node_id in value:
            if (
                not isinstance(node_id, int)
                or isinstance(node_id, bool)
            ):
                raise ValueError(
                    f"{field_name} for node {owner_id} must contain "
                    "integer IDs only."
                )

            if node_id not in all_node_ids:
                raise ValueError(
                    f"{field_name} for node {owner_id} references "
                    f"unknown node ID {node_id}."
                )

            if node_id == owner_id:
                raise ValueError(
                    f"Node {owner_id} cannot reference itself."
                )

            cleaned_ids.append(node_id)

        if len(cleaned_ids) != len(set(cleaned_ids)):
            raise ValueError(
                f"{field_name} for node {owner_id} contains "
                "duplicate IDs."
            )

        return cleaned_ids

    for index, placement in enumerate(placement_suggestions):
        if not isinstance(placement, dict):
            raise ValueError(
                f"Placement at index {index} must be an object."
            )

        if set(placement.keys()) != required_placement_fields:
            raise ValueError(
                f"Placement at index {index} contains missing or "
                "unexpected fields."
            )

        suggested_node_id = placement[
            "suggested_node_id"
        ]

        if suggested_node_id not in suggested_node_ids:
            raise ValueError(
                f"Unknown suggested node ID: {suggested_node_id}"
            )

        if suggested_node_id in placements_by_id:
            raise ValueError(
                f"Suggested node {suggested_node_id} was placed "
                "more than once."
            )

        expected_title = suggested_nodes_by_id[
            suggested_node_id
        ]["title"]

        supplied_title = str(
            placement["suggested_node_title"]
        ).strip()

        if supplied_title != expected_title:
            raise ValueError(
                f"Suggested node {suggested_node_id} must preserve "
                f"the exact title '{expected_title}'."
            )

        prerequisites = validate_relationship_ids(
            value=placement[
                "direct_prerequisite_node_ids"
            ],
            field_name="direct_prerequisite_node_ids",
            owner_id=suggested_node_id,
        )

        successors = validate_relationship_ids(
            value=placement[
                "direct_successor_node_ids"
            ],
            field_name="direct_successor_node_ids",
            owner_id=suggested_node_id,
        )

        recommendations = validate_relationship_ids(
            value=placement[
                "recommended_predecessor_node_ids"
            ],
            field_name="recommended_predecessor_node_ids",
            owner_id=suggested_node_id,
        )
        if (
            not prerequisites
            and not successors
        ):
            raise ValueError(
                f"Suggested node {suggested_node_id} "
                "must have at least one direct "
                "prerequisite or direct successor."
            )

        if set(prerequisites) & set(successors):
            raise ValueError(
                f"Node {suggested_node_id} cannot use the same node "
                "as both prerequisite and successor."
            )

        if set(prerequisites) & set(recommendations):
            raise ValueError(
                f"Node {suggested_node_id} cannot use the same node "
                "as a prerequisite and recommended predecessor."
            )

        if set(successors) & set(recommendations):
            raise ValueError(
                f"Node {suggested_node_id} cannot recommend one of "
                "its successors as a predecessor."
            )

        explanation = str(
            placement["explanation"]
        ).strip()

        if not explanation:
            raise ValueError(
                f"Node {suggested_node_id} must have an explanation."
            )

        cleaned_placement = {
            "suggested_node_id": suggested_node_id,
            "suggested_node_title": supplied_title,
            "direct_prerequisite_node_ids": prerequisites,
            "direct_successor_node_ids": successors,
            "recommended_predecessor_node_ids": recommendations,
            "explanation": explanation,
        }

        placements_by_id[suggested_node_id] = (
            cleaned_placement
        )

        cleaned_placements.append(
            cleaned_placement
        )

    if set(placements_by_id) != suggested_node_ids:
        missing_ids = sorted(
            suggested_node_ids - set(placements_by_id)
        )

        raise ValueError(
            f"Missing placements for suggested node IDs: {missing_ids}"
        )

    # Check consistency only for relationships between suggested nodes.
    for node_id, placement in placements_by_id.items():
        for successor_id in placement[
            "direct_successor_node_ids"
        ]:
            if successor_id in suggested_node_ids:
                if node_id not in placements_by_id[successor_id][
                    "direct_prerequisite_node_ids"
                ]:
                    raise ValueError(
                        f"Relationship {node_id} -> {successor_id} "
                        "is inconsistent."
                    )

        for prerequisite_id in placement[
            "direct_prerequisite_node_ids"
        ]:
            if prerequisite_id in suggested_node_ids:
                if node_id not in placements_by_id[prerequisite_id][
                    "direct_successor_node_ids"
                ]:
                    raise ValueError(
                        f"Relationship {prerequisite_id} -> {node_id} "
                        "is inconsistent."
                    )

    existing_edge_set = {
        (
            edge["from_node_id"],
            edge["to_node_id"],
        )
        for edge in existing_edges
    }

    removal_pairs: set[tuple[int, int]] = set()
    cleaned_edges_to_remove: list[dict[str, int]] = []

    for edge in edges_to_remove:
        if not isinstance(edge, dict):
            raise ValueError(
                "Every removed edge must be an object."
            )

        if set(edge.keys()) != {
            "from_node_id",
            "to_node_id",
        }:
            raise ValueError(
                "Every removed edge must contain from_node_id "
                "and to_node_id only."
            )

        from_node_id = edge["from_node_id"]
        to_node_id = edge["to_node_id"]

        edge_pair = (
            from_node_id,
            to_node_id,
        )

        if edge_pair not in existing_edge_set:
            raise ValueError(
                f"Cannot remove non-existing edge "
                f"{from_node_id} -> {to_node_id}."
            )

        if edge_pair in removal_pairs:
            raise ValueError(
                f"Duplicate removed edge "
                f"{from_node_id} -> {to_node_id}."
            )

        removal_pairs.add(edge_pair)

        cleaned_edges_to_remove.append(
            {
                "from_node_id": from_node_id,
                "to_node_id": to_node_id,
            }
        )

    proposed_edges: set[tuple[int, int]] = set()

    for node_id, placement in placements_by_id.items():
        for prerequisite_id in placement[
            "direct_prerequisite_node_ids"
        ]:
            proposed_edges.add(
                (
                    prerequisite_id,
                    node_id,
                )
            )

        for successor_id in placement[
            "direct_successor_node_ids"
        ]:
            proposed_edges.add(
                (
                    node_id,
                    successor_id,
                )
            )

    # If a suggested node is inserted directly between an existing
    # prerequisite and successor, the original direct edge must be removed.
    for node_id, placement in placements_by_id.items():
        for prerequisite_id in placement[
            "direct_prerequisite_node_ids"
        ]:
            for successor_id in placement[
                "direct_successor_node_ids"
            ]:
                original_edge = (
                    prerequisite_id,
                    successor_id,
                )

                if (
                    original_edge in existing_edge_set
                    and original_edge not in removal_pairs
                ):
                    raise ValueError(
                        f"Node {node_id} is inserted between "
                        f"{prerequisite_id} and {successor_id}, but "
                        f"the existing edge "
                        f"{prerequisite_id} -> {successor_id} "
                        "was not included in existing_edges_to_remove."
                    )

    final_edges = (
        existing_edge_set
        - removal_pairs
    ) | proposed_edges

    if graph_has_cycle(
        node_ids=all_node_ids,
        edges=final_edges,
    ):
        raise ValueError(
            "The proposed placement creates a cycle."
        )

    return {
        "placement_suggestions": cleaned_placements,
        "existing_edges_to_remove": cleaned_edges_to_remove,
    }


# =====================================================================
# AGENT RUNNER
# =====================================================================

def run_placement_agent(
    roadmap_topic: str,
    existing_nodes: list[dict[str, Any]],
    existing_edges: list[dict[str, Any]],
    suggested_nodes: list[dict[str, Any]],
) -> dict[str, Any]:
    """
    Determine the placement of all suggested nodes.

    Inputs:
    - roadmap topic
    - existing nodes
    - existing edges
    - suggested nodes from the Node Suggestion Agent
    """

    if not isinstance(roadmap_topic, str):
        raise TypeError(
            "roadmap_topic must be a string."
        )

    if not isinstance(existing_nodes, list):
        raise TypeError(
            "existing_nodes must be a list."
        )

    if not isinstance(existing_edges, list):
        raise TypeError(
            "existing_edges must be a list."
        )

    if not isinstance(suggested_nodes, list):
        raise TypeError(
            "suggested_nodes must be a list."
        )

    roadmap_topic = roadmap_topic.strip()

    if not roadmap_topic:
        raise ValueError(
            "roadmap_topic cannot be empty."
        )

    existing_node_ids = [
        node.get("id")
        for node in existing_nodes
    ]

    if any(
        not isinstance(node_id, int)
        or isinstance(node_id, bool)
        for node_id in existing_node_ids
    ):
        raise ValueError(
            "Every existing node must have an integer ID."
        )

    if len(existing_node_ids) != len(
        set(existing_node_ids)
    ):
        raise ValueError(
            "Existing node IDs must be unique."
        )

    existing_node_id_set = set(
        existing_node_ids
    )

    for edge in existing_edges:
        from_node_id = edge.get("from_node_id")
        to_node_id = edge.get("to_node_id")

        if (
            from_node_id not in existing_node_id_set
            or to_node_id not in existing_node_id_set
        ):
            raise ValueError(
                "Every existing edge must reference valid "
                "existing node IDs."
            )

    if not suggested_nodes:
        return {
            "node_suggestions_with_ids": [],
            "placement_suggestions": [],
            "existing_edges_to_remove":[],
        }

    prepared_suggested_nodes = assign_temporary_node_ids(
        existing_nodes=existing_nodes,
        suggested_nodes=suggested_nodes,
    )

    node_titles = {
        node["id"]: node["title"]
        for node in existing_nodes
    }

    readable_edges = [
        (
            f"{edge['from_node_id']} "
            f"({node_titles[edge['from_node_id']]}) "
            f"-> "
            f"{edge['to_node_id']} "
            f"({node_titles[edge['to_node_id']]})"
        )
        for edge in existing_edges
    ]

    user_message = f"""
ROADMAP TOPIC

{roadmap_topic}

EXISTING ROADMAP NODES

{json.dumps(
    existing_nodes,
    indent=2,
    ensure_ascii=False,
)}

EXISTING ROADMAP EDGES

{json.dumps(
    existing_edges,
    indent=2,
    ensure_ascii=False,
)}

READABLE EXISTING ROADMAP

{json.dumps(
    readable_edges,
    indent=2,
    ensure_ascii=False,
)}

SUGGESTED NODES WITH TEMPORARY IDS

{json.dumps(
    prepared_suggested_nodes,
    indent=2,
    ensure_ascii=False,
)}

TASK

Determine the most educationally accurate placement for every suggested node.

Think about the complete roadmap and all suggested nodes together before
returning the result.

Important:

- Return exactly one placement result for every suggested node.
- Use only supplied node IDs.
- Return direct prerequisites only.
- Return direct successors only.
- Use recommended_predecessor_node_ids only for useful but optional order.
- Do not turn an optional recommendation into a mandatory edge.
- Keep suggested-node relationships consistent from both sides.
- If a suggested node is inserted between two existing nodes, return the old
  direct edge inside existing_edges_to_remove.
- Avoid cycles.
- Preserve every suggested-node title exactly.
- Return only valid JSON.
"""

    maximum_attempts = 2
    last_error: Exception | None = None

    for attempt in range(1, maximum_attempts + 1):
        messages = [
            {
                "role": "system",
                "content": PLACEMENT_AGENT_PROMPT,
            },
            {
                "role": "user",
                "content": user_message,
            },
        ]

        if last_error is not None:
            messages.append(
                {
                    "role": "user",
                    "content": f"""
Your previous placement response failed validation.

Validation error:
{last_error}

Generate the complete response again.

Correct the placement while following these requirements:

- Use only supplied node IDs.
- Return one placement for every suggested node.
- Return only direct prerequisites and successors.
- Keep suggested-node relationships consistent.
- Keep optional recommendations separate from hard prerequisites.
- Remove an existing edge when a suggested node replaces it.
- Do not create cycles.
- Preserve suggested-node titles exactly.
- Return only the required JSON.
""",
                }
            )

        try:
            result = llm.invoke(messages)

            parsed_output = extract_json_object(
                result.content
            )

            validated_placement = validate_placement_output(
                parsed_output=parsed_output,
                existing_nodes=existing_nodes,
                existing_edges=existing_edges,
                suggested_nodes=prepared_suggested_nodes,
            )

            return {
                "node_suggestions_with_ids": prepared_suggested_nodes,
                **validated_placement,
            }

        except Exception as error:
            last_error = error

    raise RuntimeError(
        "Placement Agent failed after "
        f"{maximum_attempts} attempts: {last_error}"
    ) from last_error


if __name__ == "__main__":
    TEST_CASES = {
        # ===============================================================
        # ARTIFICIAL INTELLIGENCE
        # ===============================================================
        "ai": {
            "roadmap_topic": "artificial intelligence engineering",

            "existing_nodes": [
                {
                    "id": 1,
                    "title": "Python",
                    "description": "Programming fundamentals using Python.",
                },
                {
                    "id": 2,
                    "title": "Machine Learning",
                    "description": "Core machine learning concepts and methods.",
                },
                {
                    "id": 3,
                    "title": "Deep Learning",
                    "description": "Neural networks and deep learning techniques.",
                },
                {
                    "id": 4,
                    "title": "Prompt Engineering",
                    "description": (
                        "Techniques for designing and refining prompts "
                        "for language-model applications."
                    ),
                },
                {
                    "id": 5,
                    "title": "Retrieval-Augmented Generation",
                    "description": (
                        "Enhancing language models using external "
                        "knowledge retrieval."
                    ),
                },
                {
                    "id": 6,
                    "title": "Small Language Models",
                    "description": (
                        "Efficient smaller AI models for local, private, "
                        "and cost-efficient AI applications."
                    ),
                },
            ],

            "existing_edges": [
                {
                    "from_node_id": 1,
                    "to_node_id": 2,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 3,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 4,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 6,
                },
            ],

            "suggested_nodes": [
                {
                    "title": "Multi-Agent Systems",
                    "description": (
                        "AI systems composed of multiple specialized agents "
                        "that collaborate, coordinate tasks, and exchange "
                        "context to complete complex workflows."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "Multi-Agent Systems",
                },
                {
                    "title": "AI Agents",
                    "description": (
                        "AI agents use language models with tools, memory, "
                        "and controlled workflows to perform multi-step tasks "
                        "with limited human intervention."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "AI Agents",
                },
                {
                    "title": "Model Context Protocol",
                    "description": (
                        "A protocol for connecting AI applications to tools, "
                        "APIs, files, data sources, and external systems "
                        "through a standardized interface."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "Model Context Protocol",
                },
            ],
        },

        # ===============================================================
        # FRONTEND DEVELOPMENT
        # ===============================================================
        "frontend": {
            "roadmap_topic": "frontend development",

            "existing_nodes": [
                {
                    "id": 1,
                    "title": "HTML",
                    "description": (
                        "Structuring web pages using semantic HTML."
                    ),
                },
                {
                    "id": 2,
                    "title": "CSS",
                    "description": (
                        "Styling, layouts, responsive design, and modern "
                        "CSS fundamentals."
                    ),
                },
                {
                    "id": 3,
                    "title": "JavaScript",
                    "description": (
                        "Programming interactive web applications "
                        "using JavaScript."
                    ),
                },
                {
                    "id": 4,
                    "title": "React",
                    "description": (
                        "Component-based frontend application development."
                    ),
                },
                {
                    "id": 5,
                    "title": "REST APIs",
                    "description": (
                        "Connecting frontend applications to backend "
                        "services using REST APIs."
                    ),
                },
                {
                    "id": 6,
                    "title": "Authentication",
                    "description": (
                        "Login, registration, sessions, tokens, "
                        "and protected routes."
                    ),
                },
            ],

            "existing_edges": [
                {
                    "from_node_id": 1,
                    "to_node_id": 2,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 3,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 4,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 5,
                    "to_node_id": 6,
                },
            ],

            "suggested_nodes": [
                {
                    "title": "React Server Components",
                    "description": (
                        "A React architecture that allows selected components "
                        "to render on the server while reducing the amount of "
                        "client-side JavaScript delivered to the browser."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "React Server Components",
                },
                {
                    "title": "TypeScript",
                    "description": (
                        "A statically typed extension of JavaScript that "
                        "improves type safety, tooling, maintainability, and "
                        "developer feedback in frontend applications."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "TypeScript",
                },
                {
                    "title": "Signals-Based Reactivity",
                    "description": (
                        "A reactive state-management model that tracks "
                        "fine-grained dependencies and updates only the parts "
                        "of an interface affected by state changes."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "Signals-Based Reactivity",
                },
                {
                    "title": "Edge Computing for Frontend",
                    "description": (
                        "The use of geographically distributed edge runtimes "
                        "to execute frontend-related server logic closer to "
                        "users and reduce application latency."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "Edge Computing for Frontend",
                },
            ],
        },

        # ===============================================================
        # BACKEND ENGINEERING
        # ===============================================================
       "backend": {
    "roadmap_topic": "backend engineering",

    "existing_nodes": [
        {
            "id": 1,
            "title": "HTTP and Web Fundamentals",
            "description": (
                "Understanding clients and servers, HTTP requests and "
                "responses, methods, status codes, headers, cookies, "
                "sessions, CORS, and the request-response lifecycle."
            ),
        },
        {
            "id": 2,
            "title": "Backend Programming Fundamentals",
            "description": (
                "Learning server-side programming concepts such as "
                "variables, functions, object-oriented programming, "
                "error handling, modules, asynchronous execution, "
                "and package management."
            ),
        },
        {
            "id": 3,
            "title": "Relational Databases and SQL",
            "description": (
                "Understanding relational databases, tables, rows, "
                "columns, primary and foreign keys, constraints, joins, "
                "and SQL queries."
            ),
        },
        {
            "id": 4,
            "title": "Database Design and Transactions",
            "description": (
                "Designing normalized database schemas and learning "
                "relationships, indexes, transactions, ACID properties, "
                "concurrency, and data integrity."
            ),
        },
        {
            "id": 5,
            "title": "Backend Framework Fundamentals",
            "description": (
                "Learning a backend framework such as Laravel, Django, "
                "FastAPI, Spring Boot, ASP.NET Core, or NestJS, including "
                "project structure, configuration, routing, controllers, "
                "services, middleware, and dependency injection."
            ),
        },
        {
            "id": 6,
            "title": "ORM and Database Access",
            "description": (
                "Connecting backend applications to databases using "
                "models, migrations, query builders, repositories, "
                "relationships, transactions, and object-relational "
                "mapping."
            ),
        },
        {
            "id": 7,
            "title": "REST API Development",
            "description": (
                "Designing and building RESTful APIs using resources, "
                "routes, controllers, validation, serialization, "
                "pagination, filtering, error handling, and appropriate "
                "HTTP responses."
            ),
        },
        {
            "id": 8,
            "title": "Authentication and Authorization",
            "description": (
                "Implementing user registration, login, password hashing, "
                "sessions, tokens, OAuth, roles, permissions, policies, "
                "and protected endpoints."
            ),
        },
        {
            "id": 9,
            "title": "API Security",
            "description": (
                "Protecting backend APIs against authorization failures, "
                "injection attacks, insecure input, data exposure, "
                "cross-origin risks, abuse, and other common threats."
            ),
        },
        {
            "id": 10,
            "title": "API Documentation and Testing",
            "description": (
                "Documenting APIs using OpenAPI and testing backend "
                "applications through unit tests, integration tests, "
                "feature tests, and API endpoint tests."
            ),
        },
        {
            "id": 11,
            "title": "Caching with Redis",
            "description": (
                "Improving backend performance using application caching, "
                "query caching, cache invalidation, Redis, rate limiting, "
                "sessions, and temporary data storage."
            ),
        },
        {
            "id": 12,
            "title": "Background Jobs and Queues",
            "description": (
                "Processing asynchronous tasks such as emails, "
                "notifications, report generation, file processing, "
                "scheduled tasks, retries, and failed jobs."
            ),
        },
        {
            "id": 13,
            "title": "Docker for Backend",
            "description": (
                "Containerizing backend applications, databases, caches, "
                "and supporting services using Docker and container "
                "configuration."
            ),
        },
        {
            "id": 14,
            "title": "Backend Deployment",
            "description": (
                "Deploying backend applications using environment "
                "configuration, web servers, process managers, managed "
                "platforms, cloud services, and automated deployment "
                "pipelines."
            ),
        },
        {
            "id": 15,
            "title": "Logging and Observability",
            "description": (
                "Monitoring deployed applications using structured logs, "
                "metrics, traces, health checks, alerts, performance "
                "monitoring, and error tracking."
            ),
        },
    ],

    "existing_edges": [
        # Core backend learning path:
        # HTTP -> Programming -> Database -> Framework -> ORM -> REST API

        {
            "from_node_id": 1,
            "to_node_id": 2,
        },
        {
            "from_node_id": 2,
            "to_node_id": 3,
        },
        {
            "from_node_id": 3,
            "to_node_id": 4,
        },
        {
            "from_node_id": 4,
            "to_node_id": 5,
        },
        {
            "from_node_id": 5,
            "to_node_id": 6,
        },
        {
            "from_node_id": 6,
            "to_node_id": 7,
        },

        # Authentication and security branch

        {
            "from_node_id": 7,
            "to_node_id": 8,
        },
        {
            "from_node_id": 8,
            "to_node_id": 9,
        },

        # Testing and documentation branch

        {
            "from_node_id": 7,
            "to_node_id": 10,
        },

        # Performance and asynchronous processing branches

        {
            "from_node_id": 7,
            "to_node_id": 11,
        },
        {
            "from_node_id": 7,
            "to_node_id": 12,
        },

        # Containerization and production operations

        {
            "from_node_id": 7,
            "to_node_id": 13,
        },
        {
            "from_node_id": 13,
            "to_node_id": 14,
        },
        {
            "from_node_id": 14,
            "to_node_id": 15,
        },
    ],

    "suggested_nodes": [
        {
            "title": "AI-Integrated Backend Systems",
            "description": (
                "The integration of AI models and language-model "
                "orchestration into backend services to support "
                "intelligent features and automated workflows."
            ),
            "difficulty": "advanced",
            "based_on_trend": "AI-Augmented Backends",
        },
        {
            "title": "Cloud-Native Backend Architecture",
            "description": (
                "Designing backend systems for cloud environments "
                "using containers, managed infrastructure, distributed "
                "services, serverless computing, and automated "
                "deployment practices."
            ),
            "difficulty": "advanced",
            "based_on_trend": (
                "Cloud-Native Backend Architecture"
            ),
        },
    ],
},

        # ===============================================================
        # CYBERSECURITY
        # ===============================================================
        "cybersecurity": {
            "roadmap_topic": "cybersecurity",

            "existing_nodes": [
                {
                    "id": 1,
                    "title": "Networking Fundamentals",
                    "description": (
                        "Core networking concepts, protocols, IP addressing, "
                        "network services, and ports."
                    ),
                },
                {
                    "id": 2,
                    "title": "Linux Basics",
                    "description": (
                        "Command-line usage, permissions, processes, and "
                        "basic Linux administration."
                    ),
                },
                {
                    "id": 3,
                    "title": "Web Security",
                    "description": (
                        "Common web vulnerabilities and secure web "
                        "application practices."
                    ),
                },
                {
                    "id": 4,
                    "title": "Cryptography Basics",
                    "description": (
                        "Encryption, hashing, digital signatures, and "
                        "secure communication."
                    ),
                },
                {
                    "id": 5,
                    "title": "Penetration Testing",
                    "description": (
                        "Testing systems and applications for security "
                        "weaknesses."
                    ),
                },
                {
                    "id": 6,
                    "title": "Incident Response",
                    "description": (
                        "Detecting, investigating, containing, and responding "
                        "to security incidents."
                    ),
                },
                {
                    "id": 7,
                    "title": "Identity and Access Management",
                    "description": (
                        "Managing digital identities, authentication, authorization, "
                        "roles, access policies, least privilege, and continuous "
                        "identity verification."
                    ),
}
            ],

            "existing_edges": [
                {
                    "from_node_id": 1,
                    "to_node_id": 2,
                },
                {
                    "from_node_id": 1,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 3,
                },
                {
                    "from_node_id": 4,
                    "to_node_id": 3,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 5,
                    "to_node_id": 6,
                },
            ],

            "suggested_nodes": [
                {
                    "title": "Zero Trust Architecture",
                    "description": (
                        "A security architecture based on continuous "
                        "verification, least-privilege access, identity-aware "
                        "controls, and the assumption that no user, device, "
                        "or network location is trusted automatically."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "Zero Trust Architecture",
                },
                {
                    "title": "AI Application Security",
                    "description": (
                        "Protecting AI and language-model applications against "
                        "prompt injection, insecure model integration, "
                        "sensitive-data exposure, unsafe outputs, and other "
                        "AI-specific security risks."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "AI Security",
                },
            ],
        },

        # ===============================================================
        # DATA SCIENCE
        # ===============================================================
        "data_science": {
            "roadmap_topic": "data science",

            "existing_nodes": [
                {
                    "id": 1,
                    "title": "Python",
                    "description": (
                        "Python programming for data analysis and automation."
                    ),
                },
                {
                    "id": 2,
                    "title": "Statistics",
                    "description": (
                        "Descriptive statistics, probability, distributions, "
                        "and statistical inference."
                    ),
                },
                {
                    "id": 3,
                    "title": "SQL",
                    "description": (
                        "Querying relational databases for data analysis."
                    ),
                },
                {
                    "id": 4,
                    "title": "Data Cleaning",
                    "description": (
                        "Preparing, transforming, validating, and correcting "
                        "datasets."
                    ),
                },
                {
                    "id": 5,
                    "title": "Data Visualization",
                    "description": (
                        "Creating charts and visual explanations to "
                        "communicate data insights."
                    ),
                },
                {
                    "id": 6,
                    "title": "Machine Learning",
                    "description": (
                        "Building predictive models using supervised and "
                        "unsupervised learning."
                    ),
                },
            ],

            "existing_edges": [
                {
                    "from_node_id": 1,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 4,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 6,
                },
                {
                    "from_node_id": 4,
                    "to_node_id": 6,
                },
            ],

            "suggested_nodes": [
                {
                    "title": "Agentic AI Systems",
                    "description": (
                        "AI systems that coordinate tools and multi-step "
                        "workflows to perform data analysis, investigate "
                        "patterns, and support decision-making."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "Agentic AI",
                },
                {
                    "title": "MLOps",
                    "description": (
                        "The practices and infrastructure used to deploy, "
                        "monitor, version, maintain, and govern machine "
                        "learning models in production."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "MLOps",
                },
                {
                    "title": "Data-Centric AI",
                    "description": (
                        "A machine learning approach that prioritizes "
                        "improving data quality, labeling, consistency, and "
                        "structure instead of focusing only on model design."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "Data-Centric AI",
                },
                {
                    "title": "Automated Machine Learning",
                    "description": (
                        "The use of automated tools for data preprocessing, "
                        "feature engineering, model selection, and "
                        "hyperparameter optimization."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": (
                        "Automated Machine Learning (AutoML)"
                    ),
                },
                {
                    "title": "AI Risk Management",
                    "description": (
                        "The identification, assessment, monitoring, and "
                        "mitigation of risks associated with AI systems, "
                        "including bias, drift, reliability, and explainability."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "AI Risk Management",
                },
                {
                    "title": "AI Agent Systems",
                    "description": (
                        "Systems that use AI models, tools, memory, and "
                        "controlled workflows to perform multi-step tasks "
                        "and generate data-driven results."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "AI Agents",
                },
                {
                    "title": "AI Governance",
                    "description": (
                        "Policies, responsibilities, controls, and monitoring "
                        "processes used to ensure that AI systems are ethical, "
                        "secure, reliable, and compliant."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "AI Governance",
                },
                {
                    "title": "Semantic Layers",
                    "description": (
                        "Semantic models that provide consistent definitions, "
                        "metrics, and relationships for data across analytics "
                        "and business-intelligence systems."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "Semantic Layers",
                },
            ],
        },

        # ===============================================================
        # DATABASE SYSTEMS
        # ===============================================================
        "database": {
            "roadmap_topic": "database systems",

            "existing_nodes": [
                {
                    "id": 1,
                    "title": "Relational Databases",
                    "description": (
                        "Tables, relationships, keys, constraints, and "
                        "relational modeling."
                    ),
                },
                {
                    "id": 2,
                    "title": "SQL",
                    "description": (
                        "Querying and manipulating relational data using SQL."
                    ),
                },
                {
                    "id": 3,
                    "title": "Database Design",
                    "description": (
                        "Normalization, schema design, and entity "
                        "relationships."
                    ),
                },
                {
                    "id": 4,
                    "title": "Indexing",
                    "description": (
                        "Improving database query performance using indexes."
                    ),
                },
                {
                    "id": 5,
                    "title": "Transactions",
                    "description": (
                        "ACID properties, concurrency, isolation, and "
                        "consistency."
                    ),
                },
                {
                    "id": 6,
                    "title": "NoSQL Databases",
                    "description": (
                        "Document, key-value, column-family, and graph "
                        "database models."
                    ),
                },
            ],

            "existing_edges": [
                {
                    "from_node_id": 1,
                    "to_node_id": 2,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 3,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 1,
                    "to_node_id": 6,
                },
            ],

            "suggested_nodes": [],
        },

        # ===============================================================
        # DEVOPS
        # ===============================================================
        "devops": {
            "roadmap_topic": "devops engineering",

            "existing_nodes": [
                {
                    "id": 1,
                    "title": "Linux",
                    "description": (
                        "Linux command-line usage and system administration."
                    ),
                },
                {
                    "id": 2,
                    "title": "Git",
                    "description": (
                        "Version control, branching, merging, and "
                        "collaborative source-code management."
                    ),
                },
                {
                    "id": 3,
                    "title": "CI/CD",
                    "description": (
                        "Automating software builds, tests, delivery, "
                        "and deployment."
                    ),
                },
                {
                    "id": 4,
                    "title": "Docker",
                    "description": (
                        "Building and running applications inside containers."
                    ),
                },
                {
                    "id": 5,
                    "title": "Kubernetes",
                    "description": (
                        "Deploying and managing containerized applications "
                        "at scale."
                    ),
                },
                {
                    "id": 6,
                    "title": "Monitoring",
                    "description": (
                        "Tracking application health, logs, metrics, "
                        "and alerts."
                    ),
                },
            ],

            "existing_edges": [
                {
                    "from_node_id": 1,
                    "to_node_id": 2,
                },
                {
                    "from_node_id": 2,
                    "to_node_id": 3,
                },
                {
                    "from_node_id": 3,
                    "to_node_id": 4,
                },
                {
                    "from_node_id": 4,
                    "to_node_id": 5,
                },
                {
                    "from_node_id": 5,
                    "to_node_id": 6,
                },
            ],

            "suggested_nodes": [
                {
                    "title": "Platform Engineering",
                    "description": (
                        "Building internal developer platforms on top of container "
                        "orchestration infrastructure to provide standardized, "
                        "self-service deployment, configuration, observability, "
                        "and operational capabilities."
                    ),
                    "difficulty": "advanced",
                    "based_on_trend": "Platform Engineering",
                },
                {
                    "title": "GitOps",
                    "description": (
                        "Managing Kubernetes infrastructure and application "
                        "configuration using Git as the source of truth and automated "
                        "reconciliation to maintain the desired cluster state."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "GitOps",
                },
                {
                    "title": "DevSecOps",
                    "description": (
                        "The integration of security practices, automated "
                        "testing, vulnerability management, and security "
                        "controls throughout development and CI/CD workflows."
                    ),
                    "difficulty": "intermediate",
                    "based_on_trend": "DevSecOps",
                },
            ],
        },
    }

    # ===============================================================
    # RUN ALL TEST CASES
    # ===============================================================

    case_name = "devops"  # Change this to run a different test case

    if case_name not in TEST_CASES:
        raise ValueError(
            f"Unknown test case: '{case_name}'. "
            f"Available cases: {list(TEST_CASES.keys())}"
        )

    selected_case = TEST_CASES[case_name]

    print("\n" + "=" * 70)
    print(f"TEST CASE: {case_name.upper()}")
    print("=" * 70)

    try:
        result = run_placement_agent(
            roadmap_topic=selected_case["roadmap_topic"],
            existing_nodes=selected_case["existing_nodes"],
            existing_edges=selected_case["existing_edges"],
            suggested_nodes=selected_case["suggested_nodes"],
        )

        print(
            json.dumps(
                result,
                indent=2,
                ensure_ascii=False,
            )
        )

    except Exception as error:
        print(
            f"Test case '{case_name}' failed: {error}"
        )