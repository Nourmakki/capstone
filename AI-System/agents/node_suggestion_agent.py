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
            f"No valid JSON object found in Node Suggestion Agent response: {text}"
        )

    return json.loads(match.group(0))

NODE_SUGGESTION_PROMPT = """
You are a Node Suggestion Agent for a Computer Science learning platform.

Your responsibility is ONLY to convert technology trends into roadmap node suggestions.

You receive:
- roadmap topic
- tech trends from the Tech Trends Agent

Each trend must become exactly one roadmap node suggestion.

You must:
1. Create exactly one node suggestion for each trend title.
2. Make the node title short and descriptive.
3. Make the node title suitable for a roadmap node, not a long sentence.
4. Make the description educational and student-friendly.
5. Choose the difficulty accurately.
6. Link each node to the original trend using based_on_trend.
7. Make sure the node belongs to the roadmap topic.

You must NOT:
- create more than one node for one trend
- ignore a trend unless it is clearly unrelated
- invent new trends
- generate resources
- include sources in the output

Difficulty rules:
- beginner: the topic can be learned early with little prior domain knowledge.
- intermediate: the topic requires basic programming or basic roadmap knowledge.
- advanced: the topic requires strong previous knowledge, production experience, architecture knowledge, or advanced theory.

Return ONLY valid JSON in this exact format:

{
  "node_suggestions": [
    {
      "title": "Node title",
      "description": "Node description",
      "difficulty": "beginner | intermediate | advanced",
      "based_on_trend": "Original trend title",
    }
  ]
}

If there are no trends, return:

{
  "node_suggestions": []
}
"""

def run_node_suggestion_agent(roadmap_topic: str, trends: list[dict[str, Any]]) -> dict[str, Any]:
    """
    Convert tech trends into roadmap node suggestions using Groq.
    This is not a tool-using agent.
    """

    user_message = f"""
Roadmap topic:
{roadmap_topic}

Tech trends:
{json.dumps(trends, ensure_ascii=False)}

Task:
Convert each tech trend into exactly one roadmap node suggestion.

Important:
- Each trend should produce one node.
- The node must belong to the roadmap topic.
- Choose difficulty carefully.
- Do not include sources.
- Do not create placement suggestions.
"""

    result = llm.invoke([
        {
            "role": "system",
            "content": NODE_SUGGESTION_PROMPT,
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

    trends = [
        {
            "title": "AI Agents",
            "description": "AI agents use large language models with tools, memory, and workflows to perform tasks autonomously.",
            "confidence_score": 88,
            "sources": [
                {
                    "source_name": "LangGraph GitHub",
                    "url": "https://github.com/langchain-ai/langgraph"
                }
            ]
        },
        {
            "title": "Model Context Protocol",
            "description": "MCP is a protocol for connecting AI applications to tools, APIs, files, and external systems.",
            "confidence_score": 82,
            "sources": [
                {
                    "source_name": "MCP Documentation",
                    "url": "https://modelcontextprotocol.io/introduction"
                }
            ]
        }
    ]

    result = run_node_suggestion_agent(
        roadmap_topic=roadmap_topic,
        trends=trends,
    )

    print(json.dumps(result, indent=2, ensure_ascii=False))