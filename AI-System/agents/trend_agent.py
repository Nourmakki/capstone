import os
import json
import re
import requests
import feedparser

from typing import Any
from datetime import datetime

from dotenv import load_dotenv
from langchain.agents import create_agent
from langchain.tools import tool

from .llm import llm


load_dotenv()


# ---------------------------------------------------------------------------
# Basic helpers
# ---------------------------------------------------------------------------

def get_time() -> str:
    """
    Returns the current local date.

    The date is used in the prompt so the agent searches for current trends,
    not old or outdated topics.
    """

    now = datetime.now().astimezone()
    return now.date().isoformat()


def safe_json(data: dict[str, Any]) -> str:
    """
    Convert tool output to a JSON string.

    LangChain tools return strings, so every tool returns the same structured
    JSON shape:
    {
        "tool": "...",
        "query": "...",
        "results": [...],
        "error": null
    }
    """

    return json.dumps(data, ensure_ascii=False)


def build_web_search_suggestions(roadmap_topic: str) -> list[str]:
    """
    Build useful web_search queries for discovering trends related to a broad
    roadmap topic.

    These queries are suggestions for the agent. The agent should use them to
    search for current evidence from official docs, reports, surveys,
    conference pages, release notes, and trusted engineering sources.
    """

    topic = roadmap_topic.strip()

    return [
        f"{topic} technology trends",
        f"{topic} emerging technologies",
        f"{topic} developer trends",
        f"{topic} industry report trends",
        f"{topic} developer survey trends",
        f"{topic} official release notes new features",
        f"{topic} conference trends developers",
        f"{topic} engineering blog trends",
        f"{topic} job market skills demand",
        f"site:survey.stackoverflow.co {topic}",
        f"site:github.blog Octoverse {topic}",
        f"site:jetbrains.com/lp/devecosystem {topic}",
    ]


# ---------------------------------------------------------------------------
# Tools
# ---------------------------------------------------------------------------

@tool
def web_search(query: str) -> str:
    """
    Search the web for current technology trend evidence.

    Use this as the main discovery tool.

    Best for:
    - official documentation
    - official announcements
    - release notes
    - developer surveys
    - industry reports
    - conference pages
    - trusted engineering blogs
    - standards/security guidance
    - job-market or adoption evidence
    """

    try:
        api_key = os.getenv("SERPER_API_KEY")

        if not api_key:
            return safe_json({
                "tool": "web_search",
                "query": query,
                "results": [],
                "error": "Missing SERPER_API_KEY in .env",
            })

        response = requests.post(
            "https://google.serper.dev/search",
            headers={
                "X-API-KEY": api_key,
                "Content-Type": "application/json",
            },
            json={
                "q": query,
                "num": 8,
            },
            timeout=15,
        )

        response.raise_for_status()

        organic_results = response.json().get("organic", [])

        results = []

        for item in organic_results:
            results.append({
                "source_name": item.get("source") or "Web Source",
                "title": item.get("title", ""),
                "url": item.get("link", ""),
                "snippet": item.get("snippet", ""),
            })

        return safe_json({
            "tool": "web_search",
            "query": query,
            "results": results,
            "error": None,
        })

    except requests.exceptions.RequestException as e:
        return safe_json({
            "tool": "web_search",
            "query": query,
            "results": [],
            "error": f"Web search failed: {str(e)}",
        })

    except Exception as e:
        return safe_json({
            "tool": "web_search",
            "query": query,
            "results": [],
            "error": f"Unexpected web search tool error: {str(e)}",
        })


@tool
def search_github(query: str) -> str:
    """
    Search GitHub repositories for adoption evidence.

    Use this only after a candidate trend is discovered and the trend is related
    to an open-source framework, library, SDK, developer tool, AI agent framework,
    web/mobile framework, DevOps tool, or public repository ecosystem.
    """

    try:
        headers = {
            "Accept": "application/vnd.github+json",
        }

        github_token = os.getenv("GITHUB_TOKEN")

        if github_token:
            headers["Authorization"] = f"Bearer {github_token}"

        response = requests.get(
            "https://api.github.com/search/repositories",
            params={
                "q": query,
                "sort": "stars",
                "order": "desc",
                "per_page": 8,
            },
            headers=headers,
            timeout=15,
        )

        response.raise_for_status()

        items = response.json().get("items", [])

        results = []

        for repo in items:
            results.append({
                "source_name": "GitHub",
                "title": repo.get("full_name", ""),
                "url": repo.get("html_url", ""),
                "stars": repo.get("stargazers_count", 0),
                "description": repo.get("description") or "",
                "language": repo.get("language"),
                "updated_at": repo.get("updated_at", ""),
            })

        return safe_json({
            "tool": "search_github",
            "query": query,
            "results": results,
            "error": None,
        })

    except requests.exceptions.RequestException as e:
        return safe_json({
            "tool": "search_github",
            "query": query,
            "results": [],
            "error": f"GitHub search failed: {str(e)}",
        })

    except Exception as e:
        return safe_json({
            "tool": "search_github",
            "query": query,
            "results": [],
            "error": f"Unexpected GitHub tool error: {str(e)}",
        })


@tool
def search_semantic_scholar(query: str) -> str:
    """
    Search Semantic Scholar for academic and research evidence.

    Use this only after a candidate trend is discovered and the trend is
    research-backed or academic.

    Best for:
    - artificial intelligence
    - machine learning
    - cybersecurity research
    - systems research
    - database research
    - software engineering research
    - programming languages research
    """

    try:
        headers = {}

        api_key = os.getenv("SEMANTIC_SCHOLAR_API_KEY")

        if api_key:
            headers["x-api-key"] = api_key

        response = requests.get(
            "https://api.semanticscholar.org/graph/v1/paper/search",
            params={
                "query": query,
                "limit": 8,
                "fields": "title,url,year,authors,abstract,venue,citationCount,publicationDate,fieldsOfStudy",
            },
            headers=headers,
            timeout=20,
        )

        response.raise_for_status()

        data = response.json()
        papers = data.get("data", [])

        results = []

        for paper in papers:
            authors = paper.get("authors", [])
            author_names = [
                author.get("name", "")
                for author in authors[:3]
                if author.get("name")
            ]

            results.append({
                "source_name": "Semantic Scholar",
                "title": paper.get("title", ""),
                "url": paper.get("url", ""),
                "year": paper.get("year"),
                "publication_date": paper.get("publicationDate"),
                "venue": paper.get("venue", ""),
                "citation_count": paper.get("citationCount", 0),
                "authors": author_names,
                "fields_of_study": paper.get("fieldsOfStudy", []),
                "abstract": paper.get("abstract", ""),
            })

        return safe_json({
            "tool": "search_semantic_scholar",
            "query": query,
            "results": results,
            "error": None,
        })

    except requests.exceptions.RequestException as e:
        return safe_json({
            "tool": "search_semantic_scholar",
            "query": query,
            "results": [],
            "error": f"Semantic Scholar search failed: {str(e)}",
        })

    except Exception as e:
        return safe_json({
            "tool": "search_semantic_scholar",
            "query": query,
            "results": [],
            "error": f"Unexpected Semantic Scholar tool error: {str(e)}",
        })


@tool
def search_arxiv(query: str) -> str:
    """
    Search arXiv for recent research papers.

    Use this only after a candidate trend is discovered and the trend is very
    new, research-heavy, AI/ML-heavy, or not yet mature in industry.
    """

    try:
        response = requests.get(
            "https://export.arxiv.org/api/query",
            params={
                "search_query": f"all:{query}",
                "sortBy": "submittedDate",
                "sortOrder": "descending",
                "max_results": 8,
            },
            timeout=20,
        )

        response.raise_for_status()

        parsed = feedparser.parse(response.text)

        results = []

        for entry in parsed.entries:
            results.append({
                "source_name": "arXiv",
                "title": entry.title.strip(),
                "url": entry.link,
                "published": entry.get("published", ""),
                "summary": entry.get("summary", "").strip(),
            })

        return safe_json({
            "tool": "search_arxiv",
            "query": query,
            "results": results,
            "error": None,
        })

    except requests.exceptions.RequestException as e:
        return safe_json({
            "tool": "search_arxiv",
            "query": query,
            "results": [],
            "error": f"arXiv search failed: {str(e)}",
        })

    except Exception as e:
        return safe_json({
            "tool": "search_arxiv",
            "query": query,
            "results": [],
            "error": f"Unexpected arXiv tool error: {str(e)}",
        })


# ---------------------------------------------------------------------------
# System prompt
# ---------------------------------------------------------------------------

TECH_TRENDS_SYSTEM_PROMPT = """
You are a Tech Trends Detection Agent for a Computer Science learning platform.

Your responsibility is ONLY to discover currently important technology trends related to the given roadmap topic.

In this project, a trend means a technology, practice, framework, method, standard, architecture pattern, or ecosystem shift that is important for students to study now.

A valid trend may be:
- newly emerging
- rapidly growing
- widely adopted
- currently expected in industry
- a meaningful evolution of an existing roadmap node
- a missing modern topic that directly extends an existing roadmap node

Your highest priority is source credibility.
Return fewer trends if necessary, but never return weakly supported trends.

You receive:
- roadmap title
- roadmap topic
- existing roadmap nodes
- suggested web_search queries

You have these tools available:
- web_search
- search_github
- search_semantic_scholar
- search_arxiv

You MUST use tools before answering.
Never answer from memory or training knowledge alone.
Only return trends supported by exact URLs from tool results.

Core objective:
Discover the strongest 1 to 5 roadmap-relevant trends that:
- fit the existing roadmap direction
- connect naturally to one or more existing roadmap nodes
- can reasonably become new learnable roadmap nodes
- are important for students to study now
- are supported by credible exact sources

Do not include weak candidates just to reach a higher count.
If no credible roadmap-relevant trend is found, return an empty trends list.

Roadmap node meaning:
- A roadmap node is a learnable topic that appears as one step in a student learning roadmap.
- A roadmap node should represent a concept, technology, method, framework, tool, practice, architecture pattern, standard, or skill that students can study.
- A trend should become a roadmap node only if students would benefit from learning it as a separate topic.
- Do not return trends that are only news, product marketing, company announcements, minor version updates, or temporary hype.
- Example: Python is a valid node in a machine learning roadmap, but Python 3.12 is not a valid node because it is only a minor version update.

Roadmap compatibility rules:
- The trend must fit the existing roadmap direction.
- The trend must connect naturally to at least one existing roadmap node.
- Do not return a trend only because it belongs to the broad roadmap topic.
- Do not return a trend that belongs mainly to a framework, language, platform, or ecosystem that is not represented in the existing roadmap.
- If the trend is specific to a technology that is not in the roadmap, return it only if it directly extends, modernizes, or is clearly compatible with an existing roadmap node.
- If the trend is a missing modern topic that directly follows or extends an existing roadmap node, it may be returned.
- If the trend requires several unrelated missing foundations first, do not return it.
- If the trend belongs mainly to a missing unrelated framework, platform, or specialization, do not return it.
- If the trend is ecosystem-neutral and useful across the roadmap, it may be returned.

Examples:
- If the roadmap contains JavaScript but not TypeScript, TypeScript may be returned because it directly extends JavaScript and is currently important for frontend students.
- If the roadmap contains React but not Angular, React Server Components may be returned, but Angular-specific Signals should not be returned.
- If the roadmap contains React, Next.js may be returned because it extends React.
- If the roadmap contains Flutter but not native Android, do not return a native Android-only trend unless it is also relevant to Flutter or general mobile development.
- If the roadmap contains web security and incident response, LLM Application Security may be relevant, but a highly specialized hardware security trend should not be returned unless the roadmap direction supports it.

Discovery rules:
- Always start with web_search.
- Prefer the suggested web_search queries when useful.
- Search broadly before finalizing the answer.
- Do not stop after finding the first valid trend.
- Search for currently important technologies, emerging practices, modern frameworks, important techniques, current standards, recent reports, and ecosystem shifts that students may need to study now.
- Search for trends, not tutorials.
- Search for trends, not explanations of existing roadmap nodes.
- Do not assume trends from memory.

When relevant to the roadmap topic, consider these categories:
- frameworks and platforms
- developer tools
- architecture patterns
- security and privacy
- performance
- AI integration
- deployment and distribution
- testing and quality
- industry standards
- ecosystem shifts

Tool usage strategy:
- Use web_search as the main discovery tool.
- After web_search returns candidate trends, use specialized tools only when they fit the candidate.
- Do not call specialized tools blindly.
- Do not use specialized tools to discover random unrelated topics.
- If one tool fails, continue with the remaining relevant tools.
- Do not invent trends because a tool failed.
- Do not cite tool error messages as sources.

Use search_github when the candidate trend is related to:
- an open-source framework
- a developer tool
- a library
- an SDK
- an AI agent framework
- a web or mobile framework
- a DevOps tool
- a public repository ecosystem
- open-source adoption

Do not use search_github for:
- broad roadmap topics
- generic academic ideas
- topics with no open-source project relevance
- unrelated popular repositories

Use search_semantic_scholar when the candidate trend is research-backed or academic, especially in:
- artificial intelligence
- machine learning
- cybersecurity
- systems
- databases
- software engineering
- programming languages

Use search_arxiv when the candidate trend is:
- very new
- research-heavy
- AI/ML-heavy
- based on recent papers
- not yet mature in industry

Do not use arXiv or Semantic Scholar as the only evidence for an industry trend unless the trend is clearly research-focused.

Trusted source requirement:
- The most important requirement is source credibility.
- A trend should be returned only when it is supported by trustworthy technical sources.
- Weak sources may help discover a possible trend, but they must not appear as final sources.
- If web_search finds a weak source mentioning a trend, use it only as a clue and search again for stronger sources.
- If stronger sources cannot be found, do not return the trend.
- It is better to return fewer trends with strong sources than many trends with weak sources.

Preferred final sources:
- official documentation
- official announcements
- official release notes
- standards bodies
- security frameworks
- developer surveys
- industry reports
- conference pages
- trusted engineering blogs
- major open-source repositories directly related to the trend
- research papers from Semantic Scholar or arXiv

Do NOT use these as final sources:
- Reddit posts
- LinkedIn posts
- forums
- Q&A pages
- bootcamp pages
- career-advice pages
- generic SEO blogs
- promotional course pages
- generic company marketing pages with no technical evidence
- listicles that only summarize trends without technical support
- social media posts
- community discussions

Evidence quality ranking:

1. Strong evidence:
   - official documentation
   - official announcements
   - official release notes
   - developer surveys
   - industry reports
   - conference pages
   - standards bodies
   - trusted engineering blogs
   - authoritative security frameworks
   - official platform documentation

2. Good supporting evidence:
   - major GitHub repositories
   - Semantic Scholar papers
   - arXiv papers
   - respected technical reports
   - vendor engineering blogs with concrete technical detail

3. Educational-market evidence:
   - Coursera courses
   - Udemy courses
   - university course pages
   - professional training pages

Educational-market evidence may support that a topic is teachable or has learner demand, but it must not be used as primary proof that a trend is technically important, widely adopted, or currently expected in industry.

4. Weak evidence:
   - forums
   - Q&A pages
   - general blogs
   - general news
   - social media
   - LinkedIn posts
   - Reddit posts
   - community discussions
   - bootcamp pages
   - career-advice pages
   - low-quality SEO pages
   - promotional course pages with no technical evidence

Domain-aware source preferences:
- For cybersecurity trends, prefer NIST, OWASP, CISA, ENISA, ISO, standards bodies, official security reports, and respected industry reports.
- For AI and machine learning trends, prefer official model/framework documentation, research papers, major AI reports, conference papers, trusted engineering blogs, and major open-source repositories.
- For web development trends, prefer official framework documentation, release notes, web standards, browser documentation, developer surveys, trusted engineering blogs, and major open-source repositories.
- For mobile development trends, prefer official Android, iOS, Flutter, and React Native documentation, platform release notes, mobile engineering blogs, privacy/security documentation, and major open-source repositories.
- For cloud and DevOps trends, prefer CNCF, Kubernetes documentation, official cloud provider documentation, release notes, engineering blogs, industry reports, and major open-source repositories.
- For backend trends, prefer official framework documentation, API standards, cloud-native reports, security standards, database documentation, architecture reports, and trusted engineering sources.
- These source preferences are only for evaluating evidence quality. Do not return a topic unless it fits the roadmap topic and connects naturally to the existing roadmap nodes.

Roadmap relevance rules:
- Search only for trends related to the roadmap_topic.
- Return trends only if they belong to the roadmap_topic.
- Ignore trends from unrelated Computer Science domains.
- Return only trends that could reasonably become learnable roadmap nodes.
- A returned trend must be teachable as a separate student learning topic.
- A returned trend must fit the existing roadmap direction.
- A returned trend must connect naturally to at least one existing roadmap node.
- Use existing roadmap nodes to avoid redundant trends.
- If a topic already exists in the roadmap, do not return it unless there is a significant new evolution.
- Do not return a trend only because it is popular.
- Do not return a trend only because it has many GitHub stars.
- Do not return a research topic unless it is relevant for the roadmap topic and useful for students learning this roadmap.
- Do not return ecosystem-specific trends for ecosystems that are not represented in the roadmap.
- Do not return generic productivity, automation, or tooling trends just because they are popular.
- Return productivity or tooling trends only if they create a clear learnable roadmap node for this roadmap topic.
- The trend must directly affect how students design, build, secure, deploy, test, operate, or maintain systems in this domain.
- AI coding assistants, agentic coding tools, and workflow automation tools should not be returned unless they directly change the skills students need for this roadmap topic.

Existing node rules:
- Compare candidate trends with the existing roadmap nodes.
- If the roadmap already contains the same topic, do not return it.
- If the roadmap contains an older or more general version of the topic, return the trend only if the new trend is a meaningful evolution.
- If the trend is too similar to an existing node, do not return it.
- If the trend is only a small subtopic of an existing node and does not deserve a separate roadmap node, do not return it.
- If the trend directly extends an existing node, it may be returned.
- If the trend is a missing modern topic that naturally follows an existing node, it may be returned.
- If the trend is ecosystem-neutral and useful across the existing roadmap, it may be returned.

Source URL rules:
- Only cite URLs that appear verbatim in tool results.
- Never cite a URL from memory.
- Every source URL must directly support the trend.
- The URL must take the admin to the exact page where the evidence exists.
- Do not cite generic homepages.
- Do not cite broad product pages.
- Do not cite broad documentation homepages.
- Do not cite broad GitHub organization pages.
- Prefer exact URLs for documentation pages, release notes, reports, standards pages, research papers, conference pages, engineering blog articles, or repositories.

Bad source examples:
- https://openai.com/
- https://github.com/
- https://react.dev/
- https://developer.android.com/
- https://pypi.org/
- https://www.npmjs.com/

Good source examples:
- https://github.com/langchain-ai/langgraph
- https://react.dev/reference/rsc/server-components
- https://github.blog/news-insights/octoverse/...
- https://owasp.org/www-project-top-10/
- https://arxiv.org/abs/...

Before final answer:
- Review every trend you plan to return.
- Review every source you plan to cite.
- Remove sources that are weak, promotional, social, generic, or not directly supportive.
- If removing weak sources leaves a trend unsupported, remove the trend.
- Do not include weak sources just to increase the number of sources.
- Do not include weak trends just to reach 5 results.

Confidence score rules:
- confidence_score is only a rough evidence strength indicator.
- Source credibility is more important than the exact score.
- Use 75 for a valid trend supported by credible sources.
- Use 85 for a valid trend supported by strong or authoritative sources.
- Use 95 only for exceptional evidence from multiple authoritative source types.
- Do not return trends supported only by weak sources.
- Do not overthink the score. Admin review will make the final decision.

You must NOT:
- include unrelated trends
- invent trends
- invent URLs
- invent sources
- use weak sources as final sources
- use generic homepages as sources
- use broad product pages as sources
- cite a URL unless it directly supports the trend
- cite a URL unless it appeared exactly in a tool result
- include tool error messages as sources
- suggest roadmap placement
- create node suggestions
- generate tutorials
- generate quizzes
- generate projects
- generate resources
- generate workshops
- answer with explanations outside JSON

Output rules:
- Return ONLY valid JSON.
- Do not wrap JSON in markdown.
- Do not add comments before or after JSON.
- Do not include fields outside the required format.
- Do not include source snippets.
- Do not include evidence summaries.
- Do not include placement.
- Do not include node suggestions.
-Trend descriptions must clearly define the topic's technical scope and must not use vague or promotional wording.

Return ONLY this JSON format:

{
  "trends": [
    {
      "title": "Trend title",
      "description": "A precise technical description explaining what the trend is, what it does, and which technologies or systems it applies to.",
      "confidence_score": 75,
      "sources": [
        {
          "source_name": "Exact source name",
          "url": "Exact URL of the documentation page, release note, report, conference page, standard page, engineering blog, research paper, or GitHub repository"
        }
      ]
    }
  ]
}

If no relevant trends with credible sources are found, return:

{
  "trends": []
}
"""


# ---------------------------------------------------------------------------
# Agent
# ---------------------------------------------------------------------------

tech_trends_agent = create_agent(
    model=llm,
    system_prompt=TECH_TRENDS_SYSTEM_PROMPT,
    tools=[
        web_search,
        search_github,
        search_semantic_scholar,
        search_arxiv,
    ],
)


# ---------------------------------------------------------------------------
# JSON extraction
# ---------------------------------------------------------------------------

def extract_json_object(content: Any) -> dict[str, Any]:
    """
    Extract a JSON object from an LLM response.

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
        raise ValueError(
            f"No valid JSON object found in Tech Trends Agent response: {text}"
        )

    return json.loads(match.group(0))


# ---------------------------------------------------------------------------
# Public runner
# ---------------------------------------------------------------------------

def run_tech_trends_agent(
    roadmap_title: str,
    roadmap_topic: str,
    existing_nodes: list[dict[str, Any]],
) -> dict[str, Any]:
    """
    Run the Tech Trends Agent.

    Current MVP safety:
    - timeout inside tools
    - tool-level try/except
    - agent-level try/except

    This function is not a LangGraph node yet.
    """

    current_date = get_time()
    web_search_suggestions = build_web_search_suggestions(roadmap_topic)

    try:
        user_message = f"""
Current date:
{current_date}

Roadmap title:
{roadmap_title}

Roadmap topic:
{roadmap_topic}

Existing roadmap nodes:
{json.dumps(existing_nodes, ensure_ascii=False)}

Suggested web_search queries:
{json.dumps(web_search_suggestions, ensure_ascii=False)}

Task:
Discover currently important technology trends related ONLY to this roadmap topic.

Important workflow:
1. Start with web_search to discover candidate trends.
2. Use search_github only if a candidate trend is related to open-source adoption.
3. Use search_semantic_scholar only if a candidate trend is research-backed.
4. Use search_arxiv only if a candidate trend is very new or research-heavy.
5. Do not answer from memory alone.
6. Only cite URLs that appeared in tool results.

Task:
Discover currently important technology trends related ONLY to this roadmap topic.

The most important goal is to find trends that can reasonably become new roadmap nodes for students to study now.

Source quality is the top priority:
- Return fewer trends if necessary.
- Do not return trends supported only by weak sources.
- Use weak sources only as clues to search for stronger sources.
- Final sources must be credible, technical, and directly supportive.
"""

        agent_result = tech_trends_agent.invoke({
            "messages": [
                {
                    "role": "user",
                    "content": user_message,
                }
            ]
        })

        raw_output = agent_result["messages"][-1].content
        parsed_output = extract_json_object(raw_output)

        trends = parsed_output.get("trends", [])

        if not isinstance(trends, list):
            trends = []

        return {
            "trends": trends
        }

    except Exception as e:
        return {
            "trends": [],
            "error": f"Tech Trends Agent failed: {str(e)}",
        }


# ---------------------------------------------------------------------------
# Local test
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    TEST_CASES = {
        "ai": {
            "roadmap_title": "Artificial Intelligence Roadmap",
            "roadmap_topic": "artificial intelligence engineering",
            "nodes": [
                {
                    "id": 1,
                    "title": "Python",
                    "description": "Programming basics",
                },
                {
                    "id": 2,
                    "title": "Machine Learning",
                    "description": "Machine learning fundamentals",
                },
                {
                    "id": 3,
                    "title": "Deep Learning",
                    "description": "Neural networks and deep learning",
                },
                {
                    "id": 4,
                    "title": "Prompt Engineering",
                    "description": "How to write effective prompts for LLMs",
                },
                {
                    "id": 5,
                    "title": "RAG",
                    "description": "Retrieval Augmented Generation for enhancing LLMs with external knowledge sources",
                },
                {
                    "id": 6,
                    "title": "Small Language Models",
                    "description": "Efficient smaller AI models for local, private, and cost-efficient AI applications",
                },
            ],
        },

        "web": {
            "roadmap_title": "Frontend Development Roadmap",
            "roadmap_topic": "Frontend development",
            "nodes": [
                {
                    "id": 1,
                    "title": "HTML",
                    "description": "Structure of web pages using semantic HTML",
                },
                {
                    "id": 2,
                    "title": "CSS",
                    "description": "Styling, layouts, responsive design, and modern CSS fundamentals",
                },
                {
                    "id": 3,
                    "title": "JavaScript",
                    "description": "Programming language for interactive web applications",
                },
                {
                    "id": 4,
                    "title": "React framework",
                    "description": "Component-based frontend development with React",
                },
                {
                    "id": 5,
                    "title": "REST APIs",
                    "description": "Connecting frontend applications to backend services using REST",
                },
                {
                    "id": 6,
                    "title": "Authentication",
                    "description": "Login, registration, sessions, tokens, and protected routes",
                },
            ],
        },

        "mobile": {
            "roadmap_title": "Mobile Development Roadmap",
            "roadmap_topic": "mobile application development",
            "nodes": [
                {
                    "id": 1,
                    "title": "Programming Fundamentals",
                    "description": "Core programming concepts needed for mobile application development",
                },
                {
                    "id": 2,
                    "title": "Mobile UI Design",
                    "description": "Designing usable interfaces for mobile screens and touch interaction",
                },
                {
                    "id": 3,
                    "title": "Flutter",
                    "description": "Cross-platform mobile app development using Flutter and Dart",
                },
                {
                    "id": 4,
                    "title": "REST API Integration",
                    "description": "Connecting mobile apps to backend APIs",
                },
                {
                    "id": 5,
                    "title": "Local Storage",
                    "description": "Saving data locally using mobile storage options",
                },
                {
                    "id": 6,
                    "title": "Push Notifications",
                    "description": "Sending and handling mobile push notifications",
                },
            ],
        },

        "cybersecurity": {
            "roadmap_title": "Cybersecurity Roadmap",
            "roadmap_topic": "cybersecurity",
            "nodes": [
                {
                    "id": 1,
                    "title": "Networking Fundamentals",
                    "description": "Core networking concepts, protocols, IP addressing, and ports",
                },
                {
                    "id": 2,
                    "title": "Linux Basics",
                    "description": "Command line, permissions, processes, and Linux administration basics",
                },
                {
                    "id": 3,
                    "title": "Web Security",
                    "description": "Common web vulnerabilities and secure web application practices",
                },
                {
                    "id": 4,
                    "title": "Cryptography Basics",
                    "description": "Encryption, hashing, digital signatures, and secure communication",
                },
                {
                    "id": 5,
                    "title": "Penetration Testing",
                    "description": "Testing systems and applications for security weaknesses",
                },
                {
                    "id": 6,
                    "title": "Incident Response",
                    "description": "Detecting, investigating, and responding to security incidents",
                },
            ],
        },

        "cloud": {
            "roadmap_title": "Cloud Computing Roadmap",
            "roadmap_topic": "cloud computing",
            "nodes": [
                {
                    "id": 1,
                    "title": "Linux",
                    "description": "Linux operating system basics for cloud environments",
                },
                {
                    "id": 2,
                    "title": "Networking",
                    "description": "Cloud networking concepts such as VPCs, subnets, DNS, and load balancing",
                },
                {
                    "id": 3,
                    "title": "Containers",
                    "description": "Packaging applications using containers",
                },
                {
                    "id": 4,
                    "title": "Kubernetes",
                    "description": "Container orchestration and deployment using Kubernetes",
                },
                {
                    "id": 5,
                    "title": "Infrastructure as Code",
                    "description": "Managing infrastructure using code-based configuration",
                },
                {
                    "id": 6,
                    "title": "CI/CD",
                    "description": "Automated build, test, and deployment pipelines",
                },
            ],
        },

        "data_science": {
            "roadmap_title": "Data Science Roadmap",
            "roadmap_topic": "data science",
            "nodes": [
                {
                    "id": 1,
                    "title": "Python",
                    "description": "Python programming for data analysis and automation",
                },
                {
                    "id": 2,
                    "title": "Statistics",
                    "description": "Descriptive statistics, probability, distributions, and inference",
                },
                {
                    "id": 3,
                    "title": "SQL",
                    "description": "Querying relational databases for data analysis",
                },
                {
                    "id": 4,
                    "title": "Data Cleaning",
                    "description": "Preparing, transforming, and validating datasets",
                },
                {
                    "id": 5,
                    "title": "Data Visualization",
                    "description": "Creating charts and dashboards to communicate insights",
                },
                {
                    "id": 6,
                    "title": "Machine Learning",
                    "description": "Building predictive models using supervised and unsupervised learning",
                },
            ],
        },

        "backend": {
    "roadmap_title": "Backend Development Roadmap",
    "roadmap_topic": "backend engineering",
    "existing_nodes": [
        {
            "id": 1,
            "title": "HTTP and Web Fundamentals",
            "description": "HTTP methods, status codes, headers, requests, responses, cookies, CORS, and how web clients communicate with servers.",
        },
        {
            "id": 2,
            "title": "Backend Programming",
            "description": "Core backend programming using a server-side language such as JavaScript/TypeScript, Python, Java, C#, or PHP.",
        },
        {
            "id": 3,
            "title": "REST API Development",
            "description": "Designing and building RESTful APIs with routing, controllers, validation, request handling, and structured responses.",
        },
        {
            "id": 4,
            "title": "Backend Framework Choice",
            "description": "Choosing and learning one backend framework path such as Node.js with Express or NestJS, ASP.NET Core, Spring Boot, Laravel, Django, or FastAPI.",
        },
        {
            "id": 5,
            "title": "Relational Databases",
            "description": "Designing relational databases using PostgreSQL or MySQL, including tables, relationships, indexes, constraints, and SQL queries.",
        },
        {
            "id": 6,
            "title": "ORM and Database Access",
            "description": "Using ORM or query-builder tools to connect backend applications to databases, manage models, migrations, and database queries.",
        },
        {
            "id": 7,
            "title": "Authentication and Authorization",
            "description": "Implementing login, registration, password hashing, sessions, JWT, OAuth, roles, permissions, and protected endpoints.",
        },
        {
            "id": 8,
            "title": "API Security",
            "description": "Securing backend APIs against common risks such as broken authentication, injection, insecure authorization, rate abuse, and unsafe input handling.",
        },
        {
            "id": 9,
            "title": "Caching with Redis",
            "description": "Improving backend performance using Redis or similar in-memory stores for caching, sessions, rate limiting, queues, and temporary data.",
        },
        {
            "id": 10,
            "title": "Background Jobs and Queues",
            "description": "Handling long-running or asynchronous tasks such as emails, notifications, file processing, scheduled jobs, and event-driven workflows.",
        },
        {
            "id": 11,
            "title": "API Documentation and Testing",
            "description": "Documenting APIs with OpenAPI or Swagger and testing backend behavior using unit, integration, and API tests.",
        },
        {
            "id": 12,
            "title": "Docker for Backend",
            "description": "Containerizing backend applications and services using Docker for consistent development, testing, and deployment environments.",
        },
        {
            "id": 13,
            "title": "Deployment and Observability",
            "description": "Deploying backend applications and monitoring them using logs, metrics, error tracking, health checks, and basic production debugging.",
        },
    ],
},

        "devops": {
            "roadmap_title": "DevOps Roadmap",
            "roadmap_topic": "devops engineering",
            "nodes": [
                {
                    "id": 1,
                    "title": "Linux",
                    "description": "Linux command line and system administration basics",
                },
                {
                    "id": 2,
                    "title": "Git",
                    "description": "Version control using Git and remote repositories",
                },
                {
                    "id": 3,
                    "title": "CI/CD",
                    "description": "Automating build, test, and deployment workflows",
                },
                {
                    "id": 4,
                    "title": "Docker",
                    "description": "Building and running applications in containers",
                },
                {
                    "id": 5,
                    "title": "Kubernetes",
                    "description": "Deploying and managing containerized applications at scale",
                },
                {
                    "id": 6,
                    "title": "Monitoring",
                    "description": "Tracking application health, logs, metrics, and alerts",
                },
            ],
        },

        "database": {
            "roadmap_title": "Database Systems Roadmap",
            "roadmap_topic": "database systems",
            "nodes": [
                {
                    "id": 1,
                    "title": "Relational Databases",
                    "description": "Tables, relationships, keys, constraints, and relational modeling",
                },
                {
                    "id": 2,
                    "title": "SQL",
                    "description": "Querying and manipulating data using SQL",
                },
                {
                    "id": 3,
                    "title": "Database Design",
                    "description": "Normalization, schema design, and entity relationships",
                },
                {
                    "id": 4,
                    "title": "Indexing",
                    "description": "Improving query performance using indexes",
                },
                {
                    "id": 5,
                    "title": "Transactions",
                    "description": "ACID properties, concurrency, isolation, and consistency",
                },
                {
                    "id": 6,
                    "title": "NoSQL Databases",
                    "description": "Document, key-value, column-family, and graph database models",
                },
            ],
        },
    }

    # Change this value to test another roadmap:
    # ai, web, mobile, cybersecurity, cloud, data_science, backend, devops, database
    case_name = "devops"

    selected_case = TEST_CASES[case_name]

    result = run_tech_trends_agent(
        roadmap_title=selected_case["roadmap_title"],
        roadmap_topic=selected_case["roadmap_topic"],
        existing_nodes=selected_case["nodes"],
    )

    print(f"\n=== Test case: {case_name} ===\n")
    print(json.dumps(result, indent=2, ensure_ascii=False))
