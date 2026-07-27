import json
import re
from typing import Any

from llm import llm

def extract_json_object(content: Any) -> dict[str, Any]:
    if isinstance(content, dict):
        return content

    if isinstance(content, list):
        text_parts = []

        for item in content:
            if isinstance(item, str):
                text_parts.append(item)

            elif isinstance(item, dict):
                if "text" in item:
                    text_parts.append(item["text"])
                elif "content" in item:
                    text_parts.append(str(item["content"]))
                else:
                    text_parts.append(json.dumps(item, ensure_ascii=False))

            else:
                text_parts.append(str(item))

        text = "\n".join(text_parts)

    else:
        text = str(content)

    text = text.strip()

    try:
        return json.loads(text)
    except json.JSONDecodeError:
        pass

    match = re.search(r"\{.*\}", text, re.DOTALL)

    if not match:
        raise ValueError(
            f"No valid JSON object found in Placement Agent response: {text}"
        )

    return json.loads(match.group(0))


PLACEMENT_AGENT_PROMPT = """
You are a Roadmap Placement Agent for a Computer Science learning platform.

Your responsibility is ONLY to decide where each suggested new roadmap node fits inside an existing roadmap graph.

You receive:
- roadmap topic
- existing roadmap nodes
- existing roadmap edges
- suggested node suggestions

The roadmap is a directed graph:
- from_node_id means prerequisite node
- to_node_id means the node that depends on it

Your job:
For each suggested node, choose:
1. direct_prerequisite_node_ids
2. direct_successor_node_ids
3. parallel_node_ids

Definitions:
- A direct prerequisite is an existing node that should be learned immediately before the suggested node.
- A direct successor is an existing node that should be learned after the suggested node because it depends on the suggested node.
- A parallel node is an existing node that can be studied at the same learning level as the suggested node.
- Parallel nodes do NOT depend on the suggested node.
- The suggested node does NOT depend on parallel nodes.

You must return:
- suggested_node_title
- direct_prerequisite_node_ids
- direct_successor_node_ids
- parallel_node_ids
- explanation

Important rules:
- Use ONLY existing node IDs from the provided nodes list.
- Do NOT invent node IDs.
- Do NOT use node titles inside ID arrays.
- Do NOT return all earlier roadmap nodes.
- Do NOT return a range of node IDs.
- Do NOT create tutorials, quizzes, projects, resources, or workshops.
- Do NOT create new trends.
- Do NOT modify the suggested node title.
- Do NOT return database code.
- Do NOT return Laravel code.

Prerequisite rules:
- Return only direct prerequisites.
- Choose the closest required prerequisites, not every previous node.
- If node A already depends on node B, and the suggested node directly depends on A, return A only.
- Do not include B unless B is directly required separately.
- A related topic is NOT automatically a prerequisite.
- A topic that appears earlier in the roadmap is NOT automatically a prerequisite.
- Two technologies used in the same project are NOT automatically prerequisites of each other.
- If no existing node is truly required before the suggested node, return an empty list.

Successor rules:
- Return only direct successors.
- A successor means the existing node should require the suggested node before it can be learned.
- Do NOT add a successor only because it is related.
- Do NOT add a successor only because it appears later in the roadmap.
- If the suggested node is simply an optional advanced branch, direct_successor_node_ids can be empty.
- If inserting the suggested node between an existing prerequisite and an existing later node, return the later node as a direct successor.

Parallel learning rules:
- The roadmap is not a simple linear list.
- Some nodes can be studied in parallel after the same prerequisite.
- If the suggested node can be learned at the same level as another existing node, put that existing node ID in parallel_node_ids.
- Do NOT put a parallel node in direct_prerequisite_node_ids.
- Do NOT put a parallel node in direct_successor_node_ids.
- Parallel nodes are only for explaining placement; they should not create edges.

Graph reasoning rules:
- Study the existing edges before choosing placement.
- Respect the current roadmap structure.
- Do not create circular reasoning.
- Do not connect unrelated frontend and backend topics unless one truly requires the other.
- Do not connect tools/frameworks only because they are commonly used together.
- If two topics are independent after the same foundation, they should be parallel, not prerequisites.

ID rules:
- direct_prerequisite_node_ids must always be a JSON array.
- direct_successor_node_ids must always be a JSON array.
- parallel_node_ids must always be a JSON array.
- [] means no nodes for that category.
- [5] means only node ID 5.
- [5] does NOT mean nodes 1 through 5.
- If multiple node IDs are required, write every ID explicitly, for example [1, 3, 5].
- Use only IDs that exist in the provided nodes list.

Explanation rules:
- The explanation must explain why the suggested node belongs after the prerequisites, before the successors, or parallel with the parallel nodes.
- The explanation must be short and educational.
- Do not describe general relation only.
- Do not mention IDs that are not returned in the JSON arrays.

Return ONLY valid JSON in this exact format:

{
  "placement_suggestions": [
    {
      "suggested_node_title": "Suggested node title",
      "direct_prerequisite_node_ids": [1, 2],
      "direct_successor_node_ids": [6],
      "parallel_node_ids": [5],
      "explanation": "Short explanation of why this placement is correct."
    }
  ]
}

If there are no suggested nodes, return:

{
  "placement_suggestions": []
}
"""

def run_placement_agent(roadmap_topic: str, nodes: list[dict[str, Any]], edges: list[dict[str, Any]],
    node_suggestions: list[dict[str, Any]],
) -> dict[str, Any]:
    """
    Run the Placement Agent alone without LangGraph.

    Input:
    - roadmap_topic
    - existing nodes
    - existing edges
    - suggested nodes from Node Suggestion Agent

    Output:
    - placement_suggestions
    """

    user_message = f"""
Roadmap topic:
{roadmap_topic}

Existing roadmap nodes:
{json.dumps(nodes, ensure_ascii=False)}

Existing roadmap edges:
{json.dumps(edges, ensure_ascii=False)}

Suggested nodes:
{json.dumps(node_suggestions, ensure_ascii=False)}

Task:
For each suggested node, decide where it should be inserted in the roadmap graph.

Return only:
- suggested_node_title
- prerequisite_node_ids
- successor_node_ids
- explanation

Remember:
- Use only IDs from the existing roadmap nodes.
- Do not invent IDs.
- Do not suggest placement using titles.
"""

    result = llm.invoke([
        {
            "role": "system",
            "content": PLACEMENT_AGENT_PROMPT,
        },
        {
            "role": "user",
            "content": user_message,
        },
    ])

    raw_output = result.content
    parsed_output = extract_json_object(raw_output)

    return parsed_output

if __name__ == "__main__":
    roadmap_topic = "artificial intelligence"

nodes = [
    {
        "id": 1,
        "title": "Python",
        "description": "Programming basics"
    },
    {
        "id": 2,
        "title": "Machine Learning",
        "description": "Machine learning fundamentals"
    },
    {
        "id": 3,
        "title": "Deep Learning",
        "description": "Neural networks and deep learning"
    },
    {
        "id": 4,
        "title": "Prompt Engineering",
        "description": "How to write effective prompts for LLMs"
    },
    {
        "id": 5,
        "title": "RAG",
        "description": "Retrieval Augmented Generation"
    },
    {
        "id": 6,
        "title": "Small Language Models",
        "description": "Efficient smaller AI models"
    }
]

edges = [
    {
        "from_node_id": 1,
        "to_node_id": 2
    },
    {
        "from_node_id": 2,
        "to_node_id": 3
    },
    {
        "from_node_id": 3,
        "to_node_id": 4
    },
    {
        "from_node_id": 4,
        "to_node_id": 5
    },
    {
        "from_node_id": 5,
        "to_node_id": 6
    }
]

node_suggestions = [
    {
        "title": "AI Agents",
        "description": "Learn how AI agents use language models, tools, memory, and workflows to complete tasks.",
        "difficulty": "intermediate",
        "based_on_trend": "AI Agents"
    },
    {
        "title": "Model Context Protocol",
        "description": "Learn how MCP connects AI applications to tools, APIs, files, and external systems.",
        "difficulty": "intermediate",
        "based_on_trend": "Model Context Protocol"
    }
]

result = run_placement_agent(
    roadmap_topic=roadmap_topic,
    nodes=nodes,
    edges=edges,
    node_suggestions=node_suggestions,
)

print(json.dumps(result, indent=2, ensure_ascii=False))