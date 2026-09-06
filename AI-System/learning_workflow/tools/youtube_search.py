import os
import re
from html import unescape
import requests
from dotenv import load_dotenv
from pydantic import BaseModel, ConfigDict
from learning_workflow.schema import (
    NonEmptyString,
    TutorialAgentInput,
    TutorialResourceType,
)


load_dotenv()

YOUTUBE_SEARCH_URL = (
    "https://www.googleapis.com/youtube/v3/search"
)

REQUEST_TIMEOUT_SECONDS = 15
DEFAULT_MAX_RESULTS = 10
MAX_DESCRIPTION_SEARCH_TERMS = 6

class YouTubeSearchCandidate(BaseModel):
    """
    One video or playlist returned by YouTube search.

    This is only a candidate. It has not yet been
    accepted as the student's tutorial.
    """

    model_config = ConfigDict(
        extra="forbid"
    )

    resource_type: TutorialResourceType
    resource_id: NonEmptyString

    title: NonEmptyString
    channel_name: NonEmptyString
    published_at: NonEmptyString

    description: str = ""


def get_youtube_api_key() -> str:
    api_key = os.getenv(
        "YOUTUBE_API_KEY"
    )

    if not api_key:
        raise ValueError(
            "YOUTUBE_API_KEY is not configured."
        )

    return api_key

def build_tutorial_search_query(
    agent_input: TutorialAgentInput,
) -> str:

    roadmap_topic = (
        agent_input.roadmap_topic.strip()
    )

    node_title = (
        agent_input.node.title.strip()
    )

    return (
        f"{roadmap_topic} {node_title}"
    )

def search_youtube_candidates(
    agent_input: TutorialAgentInput,
    max_results: int = DEFAULT_MAX_RESULTS,
) -> list[YouTubeSearchCandidate]:

    if not 1 <= max_results <= 50:
        raise ValueError(
            "max_results must be between 1 and 50."
        )

    query = build_tutorial_search_query(
        agent_input
    )

    params = {
        "part": "snippet",
        "q": query,
        "type": "video,playlist",
        "order": "relevance",
        "relevanceLanguage": "en",
        "safeSearch": "strict",
        "maxResults": max_results,
        "key": get_youtube_api_key(),
    }

    try:
        response = requests.get(
            YOUTUBE_SEARCH_URL,
            params=params,
            timeout=REQUEST_TIMEOUT_SECONDS,
        )

        response.raise_for_status()

        data = response.json()

    except requests.RequestException as exc:
        raise RuntimeError(
            "YouTube search request failed: "
            f"{exc.__class__.__name__}."
    ) from None

    candidates = []

    seen_resources: set[
        tuple[str, str]
    ] = set()

    for item in data.get("items", []):
        id_data = item.get(
            "id",
            {},
        )

        kind = id_data.get("kind")

        if kind == "youtube#video":
            resource_type = "video"
            resource_id = id_data.get(
                "videoId"
            )

        elif kind == "youtube#playlist":
            resource_type = "playlist"
            resource_id = id_data.get(
                "playlistId"
            )

        else:
            continue

        if not resource_id:
            continue

        resource_key = (
            resource_type,
            resource_id,
        )

        if resource_key in seen_resources:
            continue

        snippet = item.get(
            "snippet",
            {},
        )

        title = unescape(
            snippet.get(
                "title",
                "",
            )
        ).strip()

        channel_name = unescape(
            snippet.get(
                "channelTitle",
                "",
            )
        ).strip()

        published_at = snippet.get(
            "publishedAt",
            "",
        ).strip()

        description = unescape(
            snippet.get(
                "description",
                "",
            )
        ).strip()

        if (
            not title
            or not channel_name
            or not published_at
        ):
            continue

        candidates.append(
            YouTubeSearchCandidate(
                resource_type=resource_type,
                resource_id=resource_id,
                title=title,
                channel_name=channel_name,
                published_at=published_at,
                description=description,
            )
        )

        seen_resources.add(
            resource_key
        )

    return candidates