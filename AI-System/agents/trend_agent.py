import os 
import json
import re
import requests
from typing import Any
from dotenv import load_dotenv
from langchain.agents import create_agent
from langchain.tools import tool
from llm import llm
from datetime import datetime


load_dotenv()

def get_time() -> str:
    """
    Returns current local date and time in a structured format.
    Useful for search tools and prompts.
    """
    now = datetime.now().astimezone()
    return now.date().isoformat()
    

TECH_TRENDS_SYSTEM_PROMPT = """
You are a Tech Trends Detection Agent for a Computer Science learning platform.

Your responsibility is ONLY to detect technology trends related to the given roadmap topic.

You receive:
- roadmap title
- roadmap topic
- existing roadmap nodes

You must:
1. Search only for trends related to the roadmap_topic.
2. Return trends only if they are relevant to this roadmap topic.
3. Ignore trends from unrelated CS domains.
4. Use existing roadmap nodes to avoid redundant trends.
5. If a topic already exists in the roadmap, do not return it unless there is a significant new evolution.


You must NOT:
- include unrelated trends
- invent URLs
- invent sources
- use generic homepages as sources
- use broad product pages as sources
- use a URL unless it directly supports the trend

Source URL rules:
- Every source URL must be specific and directly support the trend.
- The URL must take the admin to the exact page where the trend evidence exists.
- Prefer exact URLs for:
  - research papers
  - official announcements
  - official documentation pages
  - GitHub repositories
  - developer conference pages
  - industry reports
  - developer survey reports
  - engineering blog articles

Do NOT use generic URLs such as:
- company homepages
- product landing pages
- general technology pages
- broad documentation homepages
- broad research homepages

Bad examples:
- https://deepmind.google/
- https://deepmind.google/technologies/gemini/
- https://openai.com/
- https://github.com/
- https://react.dev/
- https://developer.android.com/

Good examples:
- https://deepmind.google/discover/blog/...
- https://openai.com/research/...
- https://github.com/langchain-ai/langgraph
- https://react.dev/reference/rsc/server-components
- https://survey.stackoverflow.co/...
- https://github.blog/news-insights/octoverse/...

If you are not sure that the URL is the exact source of the evidence, do not include it.
If you cannot provide exact source URLs, return:
{
  "trends": []
}

Accuracy rules:
- Search official documentation, GitHub, developer conferences, industry reports, research papers, trusted engineering blogs, developer surveys, and job-market evidence.
- Ignore weak random blogs, SEO articles, vague marketing pages, and unrelated results.
- Do not give high confidence unless the trend is supported by credible sources.
- LinkedIn/news/blogs can support a trend, but they should not be the only reason for high confidence.
- If the evidence is weak, do not include the trend.

Confidence score rules:
- 90 to 100: strong trend with multiple credible sources.
- 75 to 89: likely trend with credible evidence.
- 60 to 74: emerging trend with limited but relevant evidence.
- Below 60: do not include it.
-A trend may be returned with one credible exact source, but its confidence_score must not exceed 74.
-For confidence_score 75 or higher, the trend must have at least two credible exact sources.
-For confidence_score 90 or higher, the trend must have multiple strong sources, such as official documentation plus GitHub, conference, research, industry report, or developer survey evidence.
-If only one source exists but it is credible, include the trend with moderate confidence so the admin can review it.

Return ONLY valid JSON in this exact format:

{
  "trends": [
    {
      "title": "Trend title",
      "description": "Short explanation of the trend",
      "confidence_score": 85,
      "sources": [
        {
            "source_name": "Exact source name",
            "url": "Exact URL of the article, research paper, documentation page, report, conference page, or GitHub repository"
        }
        }
      ]
    }
  ]
}


- If no relevant trends are found, return:
{
  "trends": []
}
"""


tech_trends_agent = create_agent(
    model=llm,
    system_prompt=TECH_TRENDS_SYSTEM_PROMPT,
)


def extract_json_object(content: Any) -> dict[str, Any]:
    """
    Extract JSON from an LLM response.

    Handles:
    - normal string output
    - list-based message content
    - dict output
    """

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
        raise ValueError(f"No valid JSON object found in Tech Trends Agent response: {text}")

    return json.loads(match.group(0))



def run_tech_trends_agent(roadmap_title: str, roadmap_topic: str, nodes: list[dict[str, Any]],) -> dict[str, Any]:
    """
    Testable function for running the Tech Trends Agent alone.
    This is NOT a LangGraph node yet.
    """
    current_datetime = get_time()

    user_message = f"""
Current date/time:
{current_datetime}

Roadmap title:
{roadmap_title}

Roadmap topic:
{roadmap_topic}

Existing roadmap nodes:
{json.dumps(nodes, ensure_ascii=False)}

Task:
Search for current technology trends related ONLY to this roadmap topic.

Return only:
- trend title
- short description
- confidence score
- sources

Important:
- Do not suggest trends from unrelated CS fields.
- Do not create node suggestions.
- Return only tech trends with evidence and confidence score.
"""

    result = tech_trends_agent.invoke({
        "messages": [
            {
                "role": "user",
                "content": user_message,
            }
        ]
    })

    raw_output = result["messages"][-1].content
    parsed_output = extract_json_object(raw_output)

    return parsed_output

roadmap_title = "Artificial Intelligence Roadmap"
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
        "title":"RAG",
        "description":"Retrieval Augmented Generation, a technique for enhancing LLMs with external knowledge sources"
    },

    {
        "id": 6,
        "title":"Small Language Models (SLMs)",
        "description":"The trend of optimizing high-performance models with fewer parameters for edge computing, privacy, and cost-efficiency, allowing AI to run locally on consumer hardware"
    }
]

result =run_tech_trends_agent(roadmap_title=roadmap_title, roadmap_topic=roadmap_topic,nodes=nodes)

print(json.dumps(result, indent=2, ensure_ascii=False))



# def get_source_name(url: str) -> str:
#     """
#     Extract readable source name from URL.
#     """

#     try:
#         domain = urlparse(url).netloc.lower()
#         domain = domain.replace("www.", "")

#         if not domain:
#             return "Unknown Source"

#         return domain

#     except Exception:
#         return "Unknown Source"
# # @tool
# # def search_cs_trend_sources(roadmap_topic: str) -> str:
# #     """
# #     Search the web using SerpApi Brave AI Mode for current technology trends
# #     related to one Computer Science roadmap topic.

# #     The input must be only the roadmap topic.
# #     """

# #     api_key = os.getenv("SERPAPI_API_KEY")

# #     if not api_key:
# #         return json.dumps({
# #             "search_summary": "",
# #             "sources": [],
# #             "error": "Missing SERPAPI_API_KEY in .env"
# #         }, ensure_ascii=False)

# #     current_datetime = get_time()

# #     query = (
# #         f"{current_datetime}\n\n"
# #         f"Find current accurate technology trends related ONLY to this Computer Science roadmap topic: "
# #         f"{roadmap_topic}.\n\n"
# #         f"Use credible sources such as official documentation, GitHub, developer conferences, "
# #         f"industry reports, research papers, developer surveys, job market signals, and trusted engineering blogs.\n\n"
# #         f"Do not include unrelated Computer Science domains."
# #     )

# #     try:
# #         response = requests.get(
# #             "https://serpapi.com/search.json",
# #             params={
# #                 "engine": "brave_ai_mode",
# #                 "q": query,
# #                 "country": "us",
# #                 "language": "en",
# #                 "safeSearch": "moderate",
# #                 "api_key": api_key,
# #             },
# #             timeout=(10, 120),  # 10 sec connection timeout, 120 sec read timeout
# #         )

# #         response.raise_for_status()
# #         data = response.json()

# #         if data.get("error"):
# #             return json.dumps({
# #                 "search_summary": "",
# #                 "sources": [],
# #                 "error": data["error"]
# #             }, ensure_ascii=False)

# #         sources = []
# #         seen_urls = set()

# #         for ref in data.get("references", []):
# #             url = ref.get("link") or ref.get("url") or ""

# #             if not url or url in seen_urls:
# #                 continue

# #             seen_urls.add(url)

# #             sources.append({
# #                 "source_name": ref.get("source") or get_source_name(url),
# #                 "url": url,
# #                 "title": ref.get("title", ""),
# #             })

# #         for result in data.get("web_results", []):
# #             url = result.get("link") or result.get("url") or ""

# #             if not url or url in seen_urls:
# #                 continue

# #             seen_urls.add(url)

# #             sources.append({
# #                 "source_name": result.get("source") or get_source_name(url),
# #                 "url": url,
# #                 "title": result.get("title", ""),
# #             })

# #         return json.dumps({
# #             "search_summary": data.get("markdown", ""),
# #             "sources": sources[:15],
# #             "error": None,
# #         }, ensure_ascii=False)

# #     except requests.exceptions.ReadTimeout:
# #         return json.dumps({
# #             "search_summary": "",
# #             "sources": [],
# #             "error": "SerpApi request timed out. Brave AI Mode was too slow to respond."
# #         }, ensure_ascii=False)

# #     except requests.exceptions.ConnectionError:
# #         return json.dumps({
# #             "search_summary": "",
# #             "sources": [],
# #             "error": "Connection error while calling SerpApi. Check internet connection or SerpApi availability."
# #         }, ensure_ascii=False)

# #     except requests.exceptions.HTTPError as e:
# #         return json.dumps({
# #             "search_summary": "",
# #             "sources": [],
# #             "error": f"HTTP error from SerpApi: {str(e)}"
# #         }, ensure_ascii=False)

# #     except Exception as e:
# #         return json.dumps({
# #             "search_summary": "",
# #             "sources": [],
# #             "error": f"Unexpected search tool error: {str(e)}"
# #         }, ensure_ascii=False)

# # def tech_trends_node(state: AgentState) -> dict[str, Any]:
# #     try:
# #         roadmap_title = state["roadmap_title"]
# #         roadmap_topic = state["roadmap_topic"]
# #         nodes = state.get("nodes", [])

# #         user_message = f"""
# # Roadmap title:
# # {roadmap_title}

# # Roadmap topic:
# # {roadmap_topic}

# # Existing roadmap nodes:
# # {json.dumps(nodes, ensure_ascii=False)}

# # Task:
# # Search for current technology trends related ONLY to this roadmap topic.

# # Important:
# # - Do not suggest trends from unrelated CS fields.
# # - Do not create node suggestions.
# # - Do not create tutorials, quizzes, projects, resources, or workshops.
# # - Return only trend candidates with evidence and confidence score.
# # """

# #         result = tech_trends_agent.invoke({
# #             "messages": [
# #                 {
# #                     "role": "user",
# #                     "content": user_message,
# #                 }
# #             ]
# #         })

# #         raw_output = result["messages"][-1].content
# #         parsed_output = extract_json_object(raw_output)

# #         return {
# #             "trends": parsed_output.get("trends", []),
# #             "status": "tech_trends_completed",
# #             "error": None,
# #         }

# #     except Exception as e:
# #         return {
# #             "trends": [],
# #             "status": "tech_trends_failed",
# #             "error": str(e),
# #         }