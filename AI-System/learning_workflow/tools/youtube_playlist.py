import requests

from learning_workflow.tools.youtube_search import (
    REQUEST_TIMEOUT_SECONDS,
    get_youtube_api_key,
)


YOUTUBE_PLAYLIST_ITEMS_URL = (
    "https://www.googleapis.com/youtube/v3/playlistItems"
)

MAX_PLAYLIST_VIDEOS = 50


def fetch_playlist_video_ids(
    playlist_id: str,
) -> list[str]:

    if not playlist_id:
        raise ValueError(
            "playlist_id is required."
        )

    params = {
        "part": "contentDetails",
        "playlistId": playlist_id,
        "maxResults": MAX_PLAYLIST_VIDEOS,
        "key": get_youtube_api_key(),
    }

    try:
        response = requests.get(
            YOUTUBE_PLAYLIST_ITEMS_URL,
            params=params,
            timeout=REQUEST_TIMEOUT_SECONDS,
        )

        response.raise_for_status()

        data = response.json()

    except requests.RequestException as exc:
        raise RuntimeError(
            "YouTube playlist request "
            f"failed: {exc.__class__.__name__}."
        ) from None

    if data.get("nextPageToken"):
        raise ValueError(
            "Playlist exceeds the maximum "
            f"of {MAX_PLAYLIST_VIDEOS} videos."
        )

    video_ids: list[str] = []

    for item in data.get(
        "items",
        [],
    ):
        content_details = item.get(
            "contentDetails",
            {},
        )

        video_id = content_details.get(
            "videoId"
        )

        if video_id:
            video_ids.append(
                video_id
            )

    return video_ids