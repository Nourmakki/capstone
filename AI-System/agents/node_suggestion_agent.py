import json
import re
from collections import Counter
from typing import Any

from llm import llm


ALLOWED_DIFFICULTIES = {
    "beginner",
    "intermediate",
    "advanced",
}


def extract_json_object(content: Any) -> dict[str, Any]:
    """
    Extract a JSON object from the LLM response.

    The model response may be:
    - a dictionary
    - a string
    - a list of content blocks
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

    # Remove Markdown fences if the model returns ```json ... ```.
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
        parsed_output = json.loads(text)

        if not isinstance(parsed_output, dict):
            raise ValueError(
                "Node Suggestion Agent output must be a JSON object."
            )

        return parsed_output

    except json.JSONDecodeError:
        pass

    # Fallback when the model adds text around the JSON.
    match = re.search(
        r"\{.*\}",
        text,
        re.DOTALL,
    )

    if not match:
        raise ValueError(
            "No valid JSON object found in Node Suggestion Agent "
            f"response: {text}"
        )

    parsed_output = json.loads(match.group(0))

    if not isinstance(parsed_output, dict):
        raise ValueError(
            "Node Suggestion Agent output must be a JSON object."
        )

    return parsed_output

NODE_SUGGESTION_PROMPT = """
You are a Node Suggestion Agent for a Computer Science learning platform.

Your responsibility is ONLY to transform verified technology trends into
clear and academically appropriate roadmap node suggestions for a specific
Computer Science roadmap.

You do not create database records.
You do not modify the roadmap.
You do not determine roadmap placement.
You only propose node suggestions for later admin review.

INPUT

You receive:
- roadmap topic
- verified technology trends from the Tech Trends Agent

Each technology trend contains:
- title
- description
- confidence score
- sources

Use the trend title and description to understand the educational topic.

CORE MAPPING RULE

Each input trend must become exactly one independent roadmap node suggestion.

The relationship is always:

one trend -> one node suggestion

You must:
1. Create exactly one node suggestion for every input trend.
2. Preserve the exact original trend title inside based_on_trend.
3. Ensure that every suggested node belongs to the supplied roadmap topic.
4. Use the existing roadmap nodes as educational and technical context.
5. Return the same number of node suggestions as input trends.

Do not:
- combine multiple trends into one node suggestion
- split one trend into multiple node suggestions
- omit an input trend
- duplicate an input trend
- invent a new trend
- replace a trend with a different topic

NODE TITLE RULES

Use the original trend title when it is already:
- technically accurate
- academically appropriate
- concise
- clear
- suitable as a roadmap learning topic

Modify the trend title only when improvement is necessary.

A title needs modification when it is:
- vague
- promotional
- exaggerated
- informal
- unnecessarily long
- written like a news headline
- unclear about what students will study
- unsuitable as a roadmap node

When modifying a title:
- preserve the original technical meaning
- use recognized technical terminology
- prefer approximately 2 to 6 words
- make it suitable for students and academic roadmaps
- do not add unsupported scope
- do not change the trend into a different topic

Do not rewrite a good title merely to make it different.

DESCRIPTION RULES

Use the original trend description when it is already:
- technically accurate
- clear
- educational
- student-friendly
- sufficiently specific
- suitable for a roadmap node

Do not rewrite a good description unnecessarily.

Modify or rewrite the description only when it is:
- unclear
- vague
- incomplete
- promotional
- exaggerated
- not student-friendly
- focused on popularity or market growth
- unsuitable as an educational node description
- missing the main technical learning scope

When modifying a description:
- preserve the original trend meaning
- explain what students will study
- identify the main concepts, practices, technologies, or skills
- use accurate technical terminology
- write one or two concise sentences
- make it suitable for future tutorial, quiz, and project generation
- do not introduce unsupported concepts

The description should explain:
- what the topic is
- what concepts it includes
- what students will learn to understand, design, implement, evaluate, or use

DIFFICULTY RULES
The difficulty represents the topic's actual technical level in the relevant
technology field.

Use only:
- beginner
- intermediate
- advanced

Determine difficulty using:
- prerequisite knowledge
- conceptual complexity
- implementation complexity
- mathematical or theoretical depth
- architecture complexity
- production complexity
- security or infrastructure expertise
- expected previous experience

Do not determine difficulty using:
- popularity
- novelty
- trend age
- market demand
- confidence score
- number of sources
- how simple the title sounds

BEGINNER
Use beginner when:
- the topic is foundational
- little previous domain knowledge is required
- it can reasonably be learned early in the roadmap
- the concepts and implementation are relatively straightforward

INTERMEDIATE
Use intermediate when:
- programming fundamentals are required
- basic knowledge of the roadmap field is required
- the topic is normally studied after foundational concepts
- it involves practical implementation, integration, configuration, or
  professional development practices

Most applied frameworks, tools, and professional practices should normally
be intermediate unless they require substantial advanced prerequisites.

ADVANCED
Use advanced when:
- strong previous domain knowledge is required
- several earlier roadmap topics are prerequisites
- it involves production-scale architecture
- it involves distributed systems
- it requires advanced security or infrastructure knowledge
- it requires significant mathematics or research knowledge
- it involves complex system design and technical trade-offs

Important:
- A topic is not advanced merely because it is new.
- A topic is not beginner merely because its definition is easy.
- Judge the knowledge required to use the topic competently in practice.

EXISTING ROADMAP NODE RULES

Use existing roadmap nodes to:
- understand the roadmap scope
- understand the expected student level
- choose roadmap-appropriate terminology
- identify whether the trend represents an evolution of an existing topic

If a trend resembles an existing node:
- still return exactly one suggestion
- do not omit the trend
- do not invent a different topic
- make the distinct scope clear when the trend is a meaningful evolution
- allow the admin to decide whether it should be rejected as a duplicate

TRACEABILITY RULES
Every node suggestion must contain based_on_trend.

based_on_trend must:
- contain the exact original trend title
- remain unchanged
- be a string
- contain only one trend title
- appear exactly once in the output

Do not replace based_on_trend with the improved node title.


OUTPUT RESTRICTIONS
You must NOT:
- omit trends
- duplicate trends
- invent trends
- include source names
- include source URLs
- include confidence scores
- evaluate source credibility
- include explanations outside JSON
- add fields outside the required format

FINAL SELF-CHECK

Before returning the output, verify:

1. The number of node suggestions equals the number of input trends.
2. Every input trend is represented exactly once.
3. Every node contains one based_on_trend value.
4. Every based_on_trend exactly matches an original input trend title.
5. No trend was omitted.
6. No new trend was invented.
7. Good original titles were preserved.
8. Titles were changed only when improvement was necessary.
9. Good original descriptions were preserved.
10. Descriptions were changed only when improvement was necessary.
11. Every difficulty reflects the topic's actual technical level.
12. Every node belongs to the supplied roadmap topic.
13. The response contains only valid JSON.

OUTPUT FORMAT

Return ONLY valid JSON.

Do not:
- wrap the JSON in Markdown
- add text before or after the JSON
- add comments
- use trailing commas
- add extra fields

Return exactly this format:

{
  "node_suggestions": [
    {
      "title": "Node title",
      "description": "Node description",
      "difficulty": "intermediate",
      "based_on_trend": "Exact original trend title"
    }
  ]
}

If there are no trends, return:

{
  "node_suggestions": []
}
"""


def validate_node_suggestions(parsed_output: dict[str, Any],trends: list[dict[str, Any]],) -> dict[str, Any]:
    """
    Validate the one-to-one relationship between trends and node suggestions.
    """

    allowed_output_fields = {
        "node_suggestions",
    }

    unexpected_output_fields = (
        set(parsed_output.keys()) - allowed_output_fields
    )

    if unexpected_output_fields:
        raise ValueError(
            "Unexpected top-level fields: "
            f"{sorted(unexpected_output_fields)}"
        )

    node_suggestions = parsed_output.get("node_suggestions")

    if not isinstance(node_suggestions, list):
        raise ValueError(
            "node_suggestions must be a list."
        )

    trend_titles: list[str] = []

    for index, trend in enumerate(trends):
        if not isinstance(trend, dict):
            raise ValueError(
                f"Trend at index {index} must be an object."
            )

        trend_title = str(
            trend.get("title", "")
        ).strip()

        if not trend_title:
            raise ValueError(
                f"Trend at index {index} has no valid title."
            )

        trend_titles.append(trend_title)

    normalized_trend_titles = [
        title.casefold()
        for title in trend_titles
    ]  

    if len(normalized_trend_titles) != len(set(normalized_trend_titles)):
        raise ValueError(
         "Input trend titles must be unique, including differences "
         "in uppercase and lowercase letters."
    )


    if len(node_suggestions) != len(trend_titles):
        raise ValueError(
            "The number of node suggestions must equal the number "
            "of input trends. "
            f"Expected {len(trend_titles)}, "
            f"received {len(node_suggestions)}."
        )

    expected_trend_counts = Counter(trend_titles)
    represented_trend_titles: list[str] = []
    cleaned_suggestions: list[dict[str, Any]] = []

    allowed_suggestion_fields = {
        "title",
        "description",
        "difficulty",
        "based_on_trend",
    }

    for index, suggestion in enumerate(node_suggestions):
        if not isinstance(suggestion, dict):
            raise ValueError(
                f"Node suggestion at index {index} must be an object."
            )

        unexpected_fields = (
            set(suggestion.keys()) - allowed_suggestion_fields
        )

        if unexpected_fields:
            raise ValueError(
                f"Node suggestion at index {index} contains unexpected "
                f"fields: {sorted(unexpected_fields)}"
            )

        title = str(
            suggestion.get("title", "")
        ).strip()

        description = str(
            suggestion.get("description", "")
        ).strip()

        difficulty = str(
            suggestion.get("difficulty", "")
        ).strip().lower()

        based_on_trend = suggestion.get("based_on_trend")

        if not title:
            raise ValueError(
                f"Node suggestion at index {index} has no title."
            )

        if not description:
            raise ValueError(
                f"Node suggestion '{title}' has no description."
            )

        if difficulty not in ALLOWED_DIFFICULTIES:
            raise ValueError(
                f"Node suggestion '{title}' has invalid difficulty: "
                f"{difficulty}. Allowed values: "
                f"{sorted(ALLOWED_DIFFICULTIES)}"
            )

        if not isinstance(based_on_trend, str):
            raise ValueError(
                f"Node suggestion '{title}' must contain a string "
                "based_on_trend value."
            )

        based_on_trend = based_on_trend.strip()

        if not based_on_trend:
            raise ValueError(
                f"Node suggestion '{title}' has an empty "
                "based_on_trend value."
            )

        if based_on_trend not in expected_trend_counts:
            raise ValueError(
                f"Node suggestion '{title}' references an unknown "
                f"trend: {based_on_trend}"
            )

        represented_trend_titles.append(based_on_trend)

        cleaned_suggestions.append(
            {
                "title": title,
                "description": description,
                "difficulty": difficulty,
                "based_on_trend": based_on_trend,
            }
        )

    represented_counts = Counter(
        represented_trend_titles
    )

    missing_trends = [
        trend_title
        for trend_title in trend_titles
        if represented_counts.get(trend_title, 0) == 0
    ]

    duplicated_trends = [
        trend_title
        for trend_title, count in represented_counts.items()
        if count > 1
    ]

    if missing_trends:
        raise ValueError(
            "The following trends were not represented: "
            f"{missing_trends}"
        )

    if duplicated_trends:
        raise ValueError(
            "The following trends were represented more than once: "
            f"{duplicated_trends}"
        )

    return {
        "node_suggestions": cleaned_suggestions
    }


def run_node_suggestion_agent(roadmap_topic: str,trends: list[dict[str, Any]],) -> dict[str, Any]:
    """
    Convert verified technology trends into independent roadmap
    node suggestions.

    Mapping:
        one trend -> one node suggestion
    """

    if not isinstance(roadmap_topic, str):
        raise TypeError(
            "roadmap_topic must be a string."
        )


    if not isinstance(trends, list):
        raise TypeError(
            "trends must be a list."
        )

    roadmap_topic = roadmap_topic.strip()

    if not roadmap_topic:
        raise ValueError(
            "roadmap_topic cannot be empty."
        )

    if not trends:
        return {
            "node_suggestions": []
        }

    user_message = f"""
ROADMAP TOPIC

{roadmap_topic}

VERIFIED TECHNOLOGY TRENDS

{json.dumps(trends,indent=2,ensure_ascii=False,
)}

TASK

Transform every verified technology trend into exactly one independent
roadmap node suggestion for this roadmap.

Mandatory requirements:

- Create exactly one node suggestion for every input trend.
- Preserve the exact original trend title in based_on_trend.
- Keep the original trend title when it is already clear, accurate, concise,
  and suitable for a roadmap node.
- Modify the trend title only when academic or technical clarity requires it.
- Keep the original trend description when it is already clear, accurate,
  educational, student-friendly, and sufficiently specific.
- Modify the description only when it needs improvement.
- Preserve the original technical meaning when rewriting.
- Determine difficulty from the topic's real prerequisites and technical
  complexity in this field.
- Use existing roadmap nodes only as educational context.
- Return only valid JSON.
"""

    try:
        result = llm.invoke(
            [
                {
                    "role": "system",
                    "content": NODE_SUGGESTION_PROMPT,
                },
                {
                    "role": "user",
                    "content": user_message,
                },
            ]
        )

        parsed_output = extract_json_object(
            result.content
        )

        return validate_node_suggestions(
            parsed_output=parsed_output,
            trends=trends,
        )

    except Exception as error:
        raise RuntimeError(
            "Node Suggestion Agent failed: "
            f"{error}"
        ) from error


if __name__ == "__main__":
    TEST_CASES = {
        # ---------------------------------------------------------------
        # Artificial Intelligence
        # ---------------------------------------------------------------
        "ai": {
            "roadmap_topic": "artificial intelligence engineering",
            "trends": [
                {
                    "title": "Multi-Agent Systems",
                    "description": (
                        "AI systems composed of multiple specialized agents "
                        "that collaborate, coordinate tasks, and exchange "
                        "context to complete complex workflows."
                    ),
                    "confidence_score": 95,
                    "sources": [
                        {
                            "source_name": (
                                "Anthropic 2026 Agentic Coding Trends Report"
                            ),
                            "url": (
                                "https://resources.anthropic.com/hubfs/"
                                "2026%20Agentic%20Coding%20Trends%20Report.pdf"
                            ),
                        },
                        {
                            "source_name": "GitHub: OpenAI Swarm",
                            "url": "https://github.com/openai/swarm",
                        },
                        {
                            "source_name": "GitHub: MetaGPT",
                            "url": (
                                "https://github.com/"
                                "FoundationAgents/MetaGPT"
                            ),
                        },
                    ],
                },
                {
                    "title": "AI Agents",
                    "description": (
                        "AI agents use language models with tools, memory, "
                        "and controlled workflows to perform multi-step "
                        "tasks with limited human intervention."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": "LangGraph GitHub",
                            "url": (
                                "https://github.com/"
                                "langchain-ai/langgraph"
                            ),
                        }
                    ],
                },
                {
                    "title": "Model Context Protocol",
                    "description": (
                        "MCP is a protocol for connecting AI applications "
                        "to tools, APIs, files, data sources, and external "
                        "systems through a standardized interface."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": "MCP Documentation",
                            "url": (
                                "https://modelcontextprotocol.io/"
                                "introduction"
                            ),
                        }
                    ],
                },
            ],
        },

        # ---------------------------------------------------------------
        # Frontend Development
        # ---------------------------------------------------------------
        "frontend": {
            "roadmap_topic": "frontend development",
            "trends": [
                {
                    "title": "React Server Components",
                    "description": (
                        "A React architecture that allows selected "
                        "components to render on the server while reducing "
                        "the amount of client-side JavaScript delivered "
                        "to the browser."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "React Server Components Documentation"
                            ),
                            "url": (
                                "https://react.dev/reference/rsc/"
                                "server-components"
                            ),
                        }
                    ],
                },
                {
                    "title": "TypeScript",
                    "description": (
                        "A statically typed extension of JavaScript that "
                        "improves type safety, tooling, maintainability, "
                        "and developer feedback in frontend applications."
                    ),
                    "confidence_score": 75,
                    "sources": [],
                },
                {
                    "title": "Signals-Based Reactivity",
                    "description": (
                        "A reactive state-management model that tracks "
                        "fine-grained dependencies and updates only the "
                        "parts of an interface affected by state changes."
                    ),
                    "confidence_score": 75,
                    "sources": [],
                },
                {
                    "title": "Edge Computing for Frontend",
                    "description": (
                        "The use of geographically distributed edge "
                        "runtimes to execute frontend-related server logic "
                        "closer to users and reduce application latency."
                    ),
                    "confidence_score": 75,
                    "sources": [],
                },
            ],
        },

        # ---------------------------------------------------------------
        # Backend Development
        # ---------------------------------------------------------------
        "backend": {
            "roadmap_topic": "backend engineering",
            "trends": [
                {
                    "title": "AI-Augmented Backends",
                    "description": (
                        "The integration of AI models and LLM orchestration "
                        "directly into backend services to enable intelligent "
                        "features, automated workflows, and data-driven "
                        "decision-making within the application architecture."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "How to Choose the Best Backend Frameworks "
                                "in 2026"
                            ),
                            "url": (
                                "https://thinkpalm.com/blogs/"
                                "how-to-choose-the-right-backend-framework-"
                                "for-ai-powered-software-a-business-leaders-"
                                "guide/"
                            ),
                        },
                        {
                            "source_name": (
                                "Why AI Won't Replace Backend Developers "
                                "in 2026"
                            ),
                            "url": (
                                "https://www.nucamp.co/blog/"
                                "why-ai-won-t-replace-backend-developers-"
                                "in-2026-but-will-change-your-job"
                            ),
                        },
                    ],
                },
                {
                    "title": "Cloud-Native Backend Architecture",
                    "description": (
                        "The shift toward designing backend systems "
                        "specifically for cloud environments, emphasizing "
                        "distributed systems, serverless computing, container "
                        "orchestration, and automated infrastructure "
                        "management as a baseline requirement."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "State of Cloud Native Development Q1 2026"
                            ),
                            "url": (
                                "https://www.cncf.io/wp-content/uploads/"
                                "2026/03/State-of-Cloud-Native-Development-"
                                "Q1-2026.pdf"
                            ),
                        },
                        {
                            "source_name": (
                                "Backend Development in 2026: Trends, "
                                "Skills, and Career Paths"
                            ),
                            "url": (
                                "https://www.refontelearning.com/blog/"
                                "backend-development-in-2026-trends-skills-"
                                "and-career-paths"
                            ),
                        },
                    ],
                },
            ],
        },

        # ---------------------------------------------------------------
        # Cybersecurity
        # ---------------------------------------------------------------
        "cybersecurity": {
            "roadmap_topic": "cybersecurity",
            "trends": [
                {
                    "title": "Zero Trust Architecture",
                    "description": (
                        "A security architecture based on continuous "
                        "verification, least-privilege access, identity-aware "
                        "controls, and the assumption that no user, device, "
                        "or network location is trusted automatically."
                    ),
                    "confidence_score": 80,
                    "sources": [],
                },
                {
                    "title": "AI Security",
                    "description": (
                        "The protection of AI and language-model applications "
                        "against threats such as prompt injection, insecure "
                        "model integration, sensitive-data exposure, and "
                        "unsafe model behavior."
                    ),
                    "confidence_score": 80,
                    "sources": [],
                },
            ],
        },
        "data_science": {
            "roadmap_topic": "data science",
            "trends": [
                {
                    "title": "Agentic AI",
                    "description": (
                        "The shift toward autonomous AI agents capable of "
                        "executing complex, multi-step data workflows and "
                        "decision-making processes without constant human "
                        "intervention."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "Gartner: Top Trends in Data and Analytics "
                                "for 2026"
                            ),
                            "url": (
                                "https://www.gartner.com/en/documents/"
                                "7445926"
                            ),
                        }
                    ],
                },
                {
                    "title": "MLOps",
                    "description": (
                        "The standardization of machine learning operations, "
                        "focusing on lifecycle management, deployment, "
                        "monitoring, and maintenance of predictive models "
                        "in production environments."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "Data Science & AI in 2026: Top Trends, "
                                "Essential Skills, and Career Strategies"
                            ),
                            "url": (
                                "https://www.refontelearning.com/blog/"
                                "data-science-ai-in-2026-top-trends-"
                                "essential-skills-and-career-strategies"
                            ),
                        },
                        {
                            "source_name": (
                                "Top 9 Data Science Trends to Watch in 2026"
                            ),
                            "url": (
                                "https://binariks.com/blog/"
                                "data-science-trends/"
                            ),
                        },
                    ],
                },
                {
                    "title": "Data-Centric AI",
                    "description": (
                        "A machine learning approach that prioritizes "
                        "improving the quality, consistency, labeling, and "
                        "structure of training data rather than focusing "
                        "only on model architecture."
                    ),
                    "confidence_score": 75,
                    "sources": [
                        {
                            "source_name": (
                                "Five Trends in AI and Data Science for 2026"
                            ),
                            "url": (
                                "https://sloanreview.mit.edu/article/"
                                "five-trends-in-ai-and-data-science-for-2026/"
                            ),
                        }
                    ],
                },
                {
                    "title": "Automated Machine Learning (AutoML)",
                    "description": (
                        "The use of automated tools and frameworks to "
                        "streamline data preprocessing, feature engineering, "
                        "model selection, and hyperparameter optimization."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "Fortune Business Insights: Automated "
                                "Machine Learning Market"
                            ),
                            "url": (
                                "https://www.fortunebusinessinsights.com/"
                                "automated-machine-learning-automl-market-"
                                "109363"
                            ),
                        },
                        {
                            "source_name": (
                                "Geniusee: Top AutoML Frameworks for "
                                "ML Teams in 2026"
                            ),
                            "url": (
                                "https://geniusee.com/single-blog/"
                                "automl-frameworks"
                            ),
                        },
                    ],
                },
                {
                    "title": "AI Risk Management",
                    "description": (
                        "The practice of identifying, measuring, and "
                        "mitigating risks associated with AI systems, "
                        "including bias, model drift, explainability, "
                        "reliability, and governance."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "NIST AI Risk Management Framework"
                            ),
                            "url": (
                                "https://www.nist.gov/itl/"
                                "ai-risk-management-framework"
                            ),
                        },
                        {
                            "source_name": (
                                "Orca Security: NIST AI RMF Explained"
                            ),
                            "url": (
                                "https://orca.security/resources/blog/"
                                "nist-ai-risk-management-framework-ai-rmf/"
                            ),
                        },
                    ],
                },
                {
                    "title": "AI Agents",
                    "description": (
                        "The shift from passive data analysis to AI agents "
                        "that can coordinate tools and workflows to identify "
                        "patterns, investigate anomalies, and generate "
                        "data-driven insights."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "Gartner Identifies the Top Trends for "
                                "Data and Analytics"
                            ),
                            "url": (
                                "https://www.gartner.com/en/newsroom/"
                                "press-releases/2026-06-16-gartner-"
                                "identifies-the-top-trends-for-data-and-"
                                "analytics"
                            ),
                        }
                    ],
                },
                {
                    "title": "AI Governance",
                    "description": (
                        "Policies, processes, responsibilities, and controls "
                        "used to ensure that AI models are ethical, secure, "
                        "compliant, reliable, and appropriately monitored."
                    ),
                    "confidence_score": 85,
                    "sources": [
                        {
                            "source_name": (
                                "Gartner Identifies the Top Trends for "
                                "Data and Analytics"
                            ),
                            "url": (
                                "https://www.gartner.com/en/newsroom/"
                                "press-releases/2026-06-16-gartner-"
                                "identifies-the-top-trends-for-data-and-"
                                "analytics"
                            ),
                        },
                        {
                            "source_name": (
                                "Domino Data Science Dictionary: "
                                "AI Governance"
                            ),
                            "url": (
                                "https://domino.ai/data-science-dictionary/"
                                "ai-governance"
                            ),
                        },
                    ],
                },
                {
                    "title": "Semantic Layers",
                    "description": (
                        "The use of semantic models and knowledge structures "
                        "to provide consistent, business-friendly definitions "
                        "and relationships for data across analytics systems."
                    ),
                    "confidence_score": 80,
                    "sources": [
                        {
                            "source_name": (
                                "Gartner Identifies the Top Trends for "
                                "Data and Analytics"
                            ),
                            "url": (
                                "https://www.gartner.com/en/newsroom/"
                                "press-releases/2026-06-16-gartner-"
                                "identifies-the-top-trends-for-data-and-"
                                "analytics"
                            ),
                        }
                    ],
                },
            ],
        },

        # ---------------------------------------------------------------
        # DevOps
        # ---------------------------------------------------------------
        "devops": {
            "roadmap_topic": "devops engineering",
            "trends": [
                {
                    "title": "Platform Engineering",
                    "description": (
                        "The discipline of building and maintaining internal "
                        "developer platforms that provide self-service "
                        "capabilities, reduce cognitive load, and standardize "
                        "infrastructure operations."
                    ),
                    "confidence_score": 95,
                    "sources": [
                        {
                            "source_name": (
                                "CNCF Blog: What Is Platform Engineering?"
                            ),
                            "url": (
                                "https://www.cncf.io/blog/2025/11/19/"
                                "what-is-platform-engineering/"
                            ),
                        },
                        {
                            "source_name": (
                                "CNCF Platform Engineering Maturity Model"
                            ),
                            "url": (
                                "https://tag-app-delivery.cncf.io/"
                                "whitepapers/platform-eng-maturity-model/"
                            ),
                        },
                    ],
                },
                {
                    "title": "GitOps",
                    "description": (
                        "Practices for managing infrastructure and "
                        "application configuration using Git as the source "
                        "of truth and automated reconciliation to maintain "
                        "the desired system state."
                    ),
                    "confidence_score": 95,
                    "sources": [
                        {
                            "source_name": (
                                "CNCF Cloud Native Glossary: GitOps"
                            ),
                            "url": "https://glossary.cncf.io/gitops/",
                        },
                        {
                            "source_name": "OpenGitOps",
                            "url": (
                                "https://www.cncf.io/projects/opengitops/"
                            ),
                        },
                    ],
                },
                {
                    "title": "DevSecOps",
                    "description": (
                        "The integration of security practices, automated "
                        "testing, vulnerability management, and security "
                        "controls throughout development and CI/CD workflows."
                    ),
                    "confidence_score": 90,
                    "sources": [
                        {
                            "source_name": "OWASP DevSecOps Guideline",
                            "url": (
                                "https://owasp.org/"
                                "www-project-devsecops-guideline/"
                            ),
                        },
                        {
                            "source_name": (
                                "OWASP DevSecOps Verification Standard"
                            ),
                            "url": (
                                "https://owasp.org/"
                                "www-project-devsecops-verification-standard/"
                            ),
                        },
                    ],
                },
            ],
        },
    }

    # Choose one:
    # ai, frontend, backend, cybersecurity,
    # data_science, database, devops
    case_name = "devops"

    selected_case = TEST_CASES[case_name]

    result = run_node_suggestion_agent(
        roadmap_topic=selected_case["roadmap_topic"],
        trends=selected_case["trends"],
    )

    print(f"\n=== Test case: {case_name} ===\n")
    print(json.dumps(result, indent=2, ensure_ascii=False))