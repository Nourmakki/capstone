import json
import re
from typing import Any

from agents.llm import llm
from learning_workflow.schema import (
    ExternalResourcesResult,
)
from learning_workflow.graph.state import (
    LearningNode,
)
from learning_workflow.tools.external_resource_search import (
    search_external_resource_candidates,
)


EXTERNAL_RESOURCES_SYSTEM_PROMPT = """
You are an External Resources Agent for a Computer Science learning platform.

Your role is to recommend optional, trustworthy resources for a student who wants
to study the current roadmap node more deeply.

These resources are enrichment material only.
They are NOT required to complete the roadmap node.
They are NOT used for quiz or project grading.

You receive:
- the current roadmap node
- web search candidates
- GitHub repository candidates

You must select only from the supplied candidates.

PRIMARY RESOURCE RULE:
- Genuine official documentation is the highest-priority external resource.
- If genuine official documentation for the current node exists among the supplied
  candidates, you MUST include it.
- When official documentation is selected, it MUST be the first resource in the
  resources list.
- Never omit genuine official documentation in favor of a GitHub repository,
  technical reference, or any other resource.
- Official documentation should normally be the student's main reference for
  deeper study of a technology, framework, language, platform, library, tool,
  database, or system.

SUPPLEMENTARY RESOURCE RULES:
- After selecting official documentation, you MAY include one GitHub repository
  if it provides meaningful practical learning value.
- You MAY also include one authoritative technical reference if it adds significant
  value beyond the official documentation.
- A technical reference may be useful even when official documentation exists.
- GitHub repositories and technical references are supplementary resources.
- Do not include supplementary resources merely to increase the number of resources.
- Return only resources that materially help the student study the current node
  more deeply.

GITHUB RULE:
- Select at most one GitHub repository.
- The repository must be directly relevant to the current roadmap node.
- It should help the student understand implementation, source code, examples,
  practical usage, project structure, or real-world application.
- Prefer an official repository when it is genuinely useful.
- A reputable non-official repository may be selected when it provides strong
  educational or practical value.
- Do not select a repository only because it has many stars.
- Popularity alone is not evidence that a repository is useful for learning.
- It is completely valid to return no GitHub repository.

TECHNICAL REFERENCE RULE:
- Select at most one technical reference.
- A technical reference should be an authoritative technical source that adds
  meaningful deeper-learning value for the current node.
- Good technical references may include:
  - standards or specifications
  - respected security references
  - browser or platform references
  - authoritative protocol references
  - standards-body documentation
  - high-quality technical guides maintained by trusted technical organizations
  - authoritative engineering references directly related to the node
- Examples of potentially authoritative sources include organizations such as
  MDN, OWASP, NIST, CISA, IETF, W3C, CNCF, or similarly trusted technical bodies,
  when relevant to the node.
- Do not include a technical reference merely because it discusses the same topic.
- It must provide useful depth beyond the primary documentation.
- Do not use random blogs, SEO pages, generic tutorials, forums, social media,
  promotional content, or low-quality educational pages as technical references.
- Do not return research papers for this MVP.

NO-OFFICIAL-DOCUMENTATION RULE:
- Some Computer Science concepts do not have genuine official documentation.
- If no genuine official documentation exists for the current node, select the
  strongest authoritative technical reference instead.
- Do not incorrectly label a generic tutorial, blog, educational website, or
  unofficial reference as official documentation.

ALLOWED RESOURCE TYPES:
- official_documentation
- github_repository
- technical_reference

EMPTY RESULT RULE:
- It is valid to return an empty resources list.
- If no supplied candidate is sufficiently authoritative, relevant, and beneficial
  for deeper study of the current node, return "resources": [].
- Never include a weak, irrelevant, low-quality, or redundant resource merely
  because the system allows up to 3 resources.
- Returning no external resources is better than recommending resources that do
  not provide meaningful learning value.

SELECTION LOGIC:
1. First identify whether genuine official documentation exists among the web candidates.
2. If genuine official documentation exists, it MUST be selected.
3. Official documentation MUST be the first resource in the resources list.
4. Then optionally select one useful GitHub repository if it adds meaningful
   practical learning value.
5. Then optionally select one authoritative technical reference if it adds
   significant deeper-learning value.
6. If official documentation genuinely does not exist, select the strongest
   authoritative technical reference as the primary resource.
7. Do not add resources merely to reach the maximum number.
8. Return between 0 and 3 resources total.
9. If no candidate provides meaningful and trustworthy deeper-learning value,
   return an empty resources list.

SOURCE QUALITY RULES:
- Prefer authoritative, trustworthy, and directly relevant sources.
- Prefer official maintainers, official organizations, standards bodies,
  respected technical organizations, and reputable repositories.
- The resource must be directly useful for learning the current roadmap node.
- Do not select a resource simply because it mentions the node title.
- Do not return duplicate or substantially redundant resources.
- If two candidates provide essentially the same value, select the stronger one.

URL SAFETY RULES:
- Do not invent URLs.
- Every selected URL must appear exactly in the supplied candidate lists.
- Do not change, shorten, reconstruct, normalize, or guess a URL.
- Do not return a URL from memory.
- Do not return duplicate URLs.
- Prefer the most specific URL that directly represents the recommended resource
  and is directly relevant to the current roadmap node.
- Do not select a generic website homepage, organization homepage, GitHub organization
  page, search-results page, or broad product homepage when a more specific relevant
  documentation, repository, specification, or technical-reference URL is available
  among the supplied candidates.
- For documentation, prefer the exact official documentation page or documentation
  section relevant to the node rather than a generic company or product homepage.
- For GitHub resources, return the exact repository URL, not github.com, an organization
  page, topic page, or search page.
- For technical references, return the exact specification, standard, guide, security
  reference, or technical page being recommended, not merely the homepage of the
  organization that publishes it.
- A documentation landing page may be selected when the roadmap node itself is broad
  and that landing page is genuinely the official documentation entry point for that
  technology.

RESOURCE-TYPE RULES:
- Use "official_documentation" only when the resource is genuinely maintained
  by the technology, project, framework, language, platform, library, tool,
  database, organization, or other official owner responsible for it.
- Use "github_repository" only for a repository supplied in the GitHub candidates.
- Use "technical_reference" only for an authoritative technical source supplied
  in the web candidates.
- Do not classify an unofficial resource as official documentation.
- For conceptual topics, vulnerabilities, algorithms, protocols, or general CS
  concepts that do not have an official owner, do not label an authoritative
  organization reference as "official_documentation". Use "technical_reference".
- "official_documentation" means official documentation for the CURRENT NODE ITSELF,
  not merely an official website that contains information about the node.
- A vendor's official documentation about a general concept, vulnerability,
  algorithm, protocol, or technique is NOT automatically official documentation
  for that concept.
- If the current node is a general concept, vulnerability, algorithm, protocol,
  or technique with no single official owner, classify authoritative sources
  such as OWASP, Microsoft Learn, MDN, NIST, IETF, or similar sources as
  "technical_reference" when they provide relevant technical guidance.
- Example: for a "SQL Injection" node, Microsoft SQL Server documentation about
  SQL injection is a technical_reference, not official_documentation for SQL Injection.
- Example: for a "FastAPI" node, FastAPI's own documentation is
  official_documentation.
  - For cybersecurity topics, prefer repositories that support defensive learning,
  secure implementation, controlled educational practice, or reputable security
  training.
- Do not select a repository primarily focused on offensive payload collections,
  bypass techniques, exploitation, or privilege escalation unless the roadmap node
  specifically requires authorized penetration-testing practice.

DESCRIPTION RULES:
- description should briefly explain what the resource contains.
- why_recommended should explain why this specific resource is valuable for
  deeper study of the current roadmap node.
- Keep both concise, concrete, and practical.
- Do not use vague promotional language.

YOU MUST NOT:
- invent resources
- invent URLs
- return resources outside the supplied candidates
- return YouTube resources
- return research papers
- return random blogs
- return SEO pages
- return forums
- return social media
- return promotional courses
- select GitHub repositories only because they are popular
- omit genuine official documentation when it exists
- add weak resources merely to reach 3 results
- return a weak resource merely to avoid an empty resources list

OUTPUT RULES:
- Return ONLY valid JSON.
- Do not use markdown.
- Do not add comments.
- Do not add explanations before or after the JSON.
- Do not include fields outside the required structure.

Return exactly this structure:

{
  "node_id": 1,
  "resources": [
    {
      "title": "Resource title",
      "url": "Exact candidate URL",
      "resource_type": "official_documentation",
      "source_name": "Source name",
      "description": "Brief description of the resource.",
      "why_recommended": "Why this resource is valuable for deeper study of the node."
    }
  ]
}

If no candidate provides meaningful, trustworthy external learning value, return:

{
  "node_id": 1,
  "resources": []
}
"""


def extract_json_object(
    content: Any,
) -> dict[str, Any]:
    if isinstance(content, dict):
        return content

    if isinstance(content, list):
        text_parts = []

        for item in content:
            if isinstance(item, str):
                text_parts.append(
                    item
                )

            elif isinstance(item, dict):
                if "text" in item:
                    text_parts.append(
                        item["text"]
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

    if text.startswith("```"):
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
                "External Resources Agent output "
                "must be a JSON object."
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
            "No valid JSON object found in "
            "External Resources Agent response."
        )

    parsed = json.loads(
        match.group(0)
    )

    if not isinstance(
        parsed,
        dict,
    ):
        raise ValueError(
            "External Resources Agent output "
            "must be a JSON object."
        )

    return parsed


def build_candidate_evidence(
    search_result: dict[str, Any],
) -> dict[str, Any]:
    return {
        "web_candidates": (
            search_result[
                "web_candidates"
            ]
        ),
        "github_candidates": (
            search_result[
                "github_candidates"
            ]
        ),
    }


def validate_selected_resources(
    result: ExternalResourcesResult,
    node: LearningNode,
    search_result: dict[str, Any],
) -> None:
    if result.node_id != node["id"]:
        raise ValueError(
            "External Resources Agent returned "
            "the wrong node_id."
        )

    web_urls = {
        candidate["url"]
        for candidate
        in search_result[
            "web_candidates"
        ]
    }

    github_urls = {
        candidate["url"]
        for candidate
        in search_result[
            "github_candidates"
        ]
    }

    allowed_urls = (
        web_urls
        | github_urls
    )

    github_resource_count = 0

    for resource in result.resources:
        if resource.url not in allowed_urls:
            raise ValueError(
                "External Resources Agent selected "
                "a URL that was not returned by search."
            )

        if (
            resource.resource_type
            == "github_repository"
        ):
            github_resource_count += 1

            if resource.url not in github_urls:
                raise ValueError(
                    "A github_repository resource "
                    "must come from GitHub candidates."
                )

        else:
            if resource.url not in web_urls:
                raise ValueError(
                    "Documentation and technical "
                    "references must come from "
                    "web search candidates."
                )

    if github_resource_count > 1:
        raise ValueError(
            "External Resources Agent may select "
            "at most one GitHub repository."
        )


def run_external_resources_agent(
    agent_input: dict[str, Any],
    regeneration_feedback: str | None = None,
    previous_external_resources_result: ( dict[str, Any] | None) = None,
) -> dict[str, Any]:
    if "node" not in agent_input:
        raise ValueError(
            "External Resources Agent requires "
            "'node'."
        )

    node: LearningNode = agent_input[
        "node"
    ]

    search_result = (
        search_external_resource_candidates(
            node
        )
    )

    web_candidates = search_result[
        "web_candidates"
    ]

    github_candidates = search_result[
        "github_candidates"
    ]

    if (
        not web_candidates
        and not github_candidates
    ):
        empty_result = ExternalResourcesResult(
            node_id=node["id"],
            resources=[],
        )

        return {
            "external_resources_result": (
                empty_result.model_dump()
            )
        }

    candidate_evidence = (
        build_candidate_evidence(
            search_result
        )
    )

    regeneration_context = ""

    if regeneration_feedback:
        previous_resources_text = ""

        if previous_external_resources_result:
            previous_resources_text = f"""
    PREVIOUS EXTERNAL RESOURCES

    {json.dumps(
        previous_external_resources_result,
        indent=2,
        ensure_ascii=False,
    )}
    """

        regeneration_context = f"""
    Manager regeneration feedback:

    {regeneration_feedback}

    {previous_resources_text}

    Important:
    Respect this feedback when selecting replacement resources, but only
    when it is compatible with the resource-quality and source rules.

    The feedback does not permit inventing URLs or selecting resources
    outside the supplied candidates.
    """

    user_message = f"""
Current roadmap node:
{json.dumps(node, ensure_ascii=False)}

Candidate resources:
{json.dumps(candidate_evidence, ensure_ascii=False)}
{regeneration_context}

Task:
Select the strongest 1 to 3 optional external resources for deeper study of this node.

Important:
- Prefer official documentation whenever a good official documentation candidate exists.
- Include at most one GitHub repository, and only if it genuinely adds learning value.
- If no GitHub repository is useful, do not include one.
- Use a technical reference when official documentation is unavailable or inappropriate.
- Use only exact URLs from the supplied candidates.
"""

    response = llm.invoke(
        [
            {
                "role": "system",
                "content": (
                    EXTERNAL_RESOURCES_SYSTEM_PROMPT
                ),
            },
            {
                "role": "user",
                "content": user_message,
            },
        ]
    )

    parsed_output = extract_json_object(
        response.text
    )

    validated_result = (
        ExternalResourcesResult.model_validate(
            parsed_output
        )
    )

    validate_selected_resources(
        validated_result,
        node,
        search_result,
    )

    return {
        "external_resources_result": (
            validated_result.model_dump()
        )
    }


if __name__ == "__main__":
    TEST_CASES = {
        "web": {
            "id": 401,
            "title": "React",
            "description": (
                "Component-based frontend development "
                "using React."
            ),
            "difficulty": "intermediate",
        },

        "backend": {
            "id": 402,
            "title": "FastAPI",
            "description": (
                "Building backend APIs using the "
                "FastAPI Python framework."
            ),
            "difficulty": "intermediate",
        },

        "ml": {
            "id": 403,
            "title": "Scikit-learn",
            "description": (
                "Machine learning using Scikit-learn "
                "for preprocessing, training, and evaluation."
            ),
            "difficulty": "intermediate",
        },

        "cybersecurity": {
            "id": 404,
            "title": "SQL Injection",
            "description": (
                "Understanding SQL injection vulnerabilities, "
                "prevention, and secure database interaction."
            ),
            "difficulty": "intermediate",
        },

        "devops": {
            "id": 405,
            "title": "Docker",
            "description": (
                "Building, packaging, and running "
                "applications using containers."
            ),
            "difficulty": "intermediate",
        },

        "cloud": {
            "id": 406,
            "title": "Kubernetes Deployments",
            "description": (
                "Deploying and managing containerized "
                "applications using Kubernetes Deployments."
            ),
            "difficulty": "intermediate",
        },

        "database": {
            "id": 407,
            "title": "PostgreSQL Indexes",
            "description": (
                "Using database indexes to improve "
                "PostgreSQL query performance."
            ),
            "difficulty": "intermediate",
        },

        "networking": {
            "id": 408,
            "title": "HTTP Caching",
            "description": (
                "Understanding HTTP cache behavior, "
                "cache-control directives, and validation."
            ),
            "difficulty": "intermediate",
        },

        "algorithms": {
            "id": 409,
            "title": "Binary Search",
            "description": (
                "Searching sorted data efficiently "
                "using the binary search algorithm."
            ),
            "difficulty": "beginner",
        },
    }

    case_name = "algorithms"

    if case_name not in TEST_CASES:
        raise ValueError(
            "Unknown test case. Choose one of: "
            + ", ".join(TEST_CASES.keys())
        )

    selected_node = TEST_CASES[
        case_name
    ]

    print(
        f"\n=== External Resources Test: "
        f"{case_name} ===\n"
    )

    print(
        "Node:"
    )

    print(
        json.dumps(
            selected_node,
            indent=2,
            ensure_ascii=False,
        )
    )

    result = run_external_resources_agent(
        {
            "node": selected_node,
        }
    )

    print(
        "\nExternal Resources Result:\n"
    )

    print(
        json.dumps(
            result,
            indent=2,
            ensure_ascii=False,
        )
    )
