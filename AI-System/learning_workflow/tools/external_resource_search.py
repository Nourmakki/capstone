import os
from typing import Any

import requests
from dotenv import load_dotenv

from learning_workflow.graph.state import LearningNode


load_dotenv()


SERPER_SEARCH_URL = "https://google.serper.dev/search"
GITHUB_SEARCH_URL = "https://api.github.com/search/repositories"

WEB_RESULTS_PER_QUERY = 3
GITHUB_RESULTS_LIMIT = 5


def build_documentation_queries(
    node: LearningNode,
) -> list[str]:
    title = node["title"].strip()

    return [
        f"{title} official documentation",
        f"{title} official docs reference",
    ]


def build_github_query(
    node: LearningNode,
) -> str:
    title = node["title"].strip()

    return title


def search_web_resources(
    query: str,
) -> list[dict[str, Any]]:
    api_key = os.getenv("SERPER_API_KEY")

    if not api_key:
        raise ValueError(
            "Missing SERPER_API_KEY in .env."
        )

    try:
        response = requests.post(
            SERPER_SEARCH_URL,
            headers={
                "X-API-KEY": api_key,
                "Content-Type": "application/json",
            },
            json={
                "q": query,
                "num": WEB_RESULTS_PER_QUERY,
            },
            timeout=15,
        )

        response.raise_for_status()

    except requests.exceptions.RequestException as error:
        raise RuntimeError(
            f"External resource web search failed: {error}"
        ) from error

    organic_results = response.json().get(
        "organic",
        [],
    )

    results = []

    for item in organic_results:
        title = item.get(
            "title",
            "",
        ).strip()

        url = item.get(
            "link",
            "",
        ).strip()

        if not title or not url:
            continue

        results.append(
            {
                "source": "web",
                "source_name": (
                    item.get("source")
                    or "Web Source"
                ),
                "title": title,
                "url": url,
                "snippet": item.get(
                    "snippet",
                    "",
                ).strip(),
                "search_query": query,
            }
        )

    return results


def search_github_resources(
    query: str,
) -> list[dict[str, Any]]:
    headers = {
        "Accept": "application/vnd.github+json",
    }

    github_token = os.getenv(
        "GITHUB_TOKEN"
    )

    if github_token:
        headers["Authorization"] = (
            f"Bearer {github_token}"
        )

    try:
        response = requests.get(
            GITHUB_SEARCH_URL,
            params={
                "q": query,
                "sort": "stars",
                "order": "desc",
                "per_page": GITHUB_RESULTS_LIMIT,
            },
            headers=headers,
            timeout=15,
        )

        response.raise_for_status()

    except requests.exceptions.RequestException as error:
        raise RuntimeError(
            f"External resource GitHub search failed: {error}"
        ) from error

    repositories = response.json().get(
        "items",
        [],
    )

    results = []

    for repository in repositories:
        title = repository.get(
            "full_name",
            "",
        ).strip()

        url = repository.get(
            "html_url",
            "",
        ).strip()

        if not title or not url:
            continue

        results.append(
            {
                "source": "github",
                "source_name": "GitHub",
                "title": title,
                "url": url,
                "description": (
                    repository.get(
                        "description"
                    )
                    or ""
                ).strip(),
                "stars": repository.get(
                    "stargazers_count",
                    0,
                ),
                "language": repository.get(
                    "language"
                ),
                "updated_at": repository.get(
                    "updated_at",
                    "",
                ),
                "owner": repository.get(
                    "owner",
                    {},
                ).get(
                    "login",
                    "",
                ),
            }
        )

    return results


def remove_duplicate_candidates(
    candidates: list[dict[str, Any]],
) -> list[dict[str, Any]]:
    unique_candidates = []
    seen_urls = set()

    for candidate in candidates:
        url = candidate.get(
            "url",
            "",
        ).strip()

        if not url:
            continue

        normalized_url = url.rstrip(
            "/"
        ).lower()

        if normalized_url in seen_urls:
            continue

        seen_urls.add(
            normalized_url
        )

        unique_candidates.append(
            candidate
        )

    return unique_candidates


def search_external_resource_candidates(
    node: LearningNode,
) -> dict[str, Any]:
    documentation_queries = (
        build_documentation_queries(
            node
        )
    )
    web_candidates = []
    web_errors = []
    for query in documentation_queries:
        try:
            results = search_web_resources(
                query
            )

            web_candidates.extend(
                results
            )

        except RuntimeError as error:
            web_errors.append(
                str(error)
            )

    github_candidates = []
    github_error = None

    try:
        github_query = build_github_query(
            node
        )

        github_candidates = (
            search_github_resources(
                github_query
            )
        )

    except RuntimeError as error:
        github_error = str(
            error
        )

    web_candidates = (
        remove_duplicate_candidates(
            web_candidates
        )
    )

    github_candidates = (
        remove_duplicate_candidates(
            github_candidates
        )
    )

    return {
        "node_id": node["id"],
        "node_title": node["title"],
        "web_candidates": web_candidates,
        "github_candidates": github_candidates,
        "search_notes": {
            "web_errors": web_errors,
            "github_error": github_error,
        },
    }