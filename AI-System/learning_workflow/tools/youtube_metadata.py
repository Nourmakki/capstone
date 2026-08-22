import re
import requests
from learning_workflow.schema import (TutorialVideo,TutorialChapter)
from learning_workflow.tools.youtube_search import (
    REQUEST_TIMEOUT_SECONDS,
    get_youtube_api_key,
)

YOUTUBE_VIDEOS_URL = ("https://www.googleapis.com/youtube/v3/videos")

CHAPTER_LINE_PATTERN = re.compile(
    r"^\s*"
    r"(?:[-*•]\s*)?"
    r"(?P<timestamp>\d{1,2}:\d{2}(?::\d{2})?)"
    r"\s*(?:[-–—:|]\s*)?"
    r"(?P<title>.+?)"
    r"\s*$"
)


def chapter_timestamp_to_seconds(
    timestamp: str,
) -> int:

    parts = [
        int(part)
        for part in timestamp.split(":")
    ]

    if len(parts) == 2:
        minutes, seconds = parts

        return (
            minutes * 60
            + seconds
        )

    if len(parts) == 3:
        hours, minutes, seconds = parts

        return (
            hours * 3600
            + minutes * 60
            + seconds
        )

    raise ValueError(
        "Invalid chapter timestamp."
    )


def extract_video_chapters(
    description: str | None,
) -> list[TutorialChapter]:

    if not description:
        return []

    chapters: list[
        TutorialChapter
    ] = []

    for line in description.splitlines():
        match = CHAPTER_LINE_PATTERN.match(
            line
        )

        if not match:
            continue

        timestamp = match.group(
            "timestamp"
        )

        title = match.group(
            "title"
        ).strip()

        if not title:
            continue

        start_seconds = (
            chapter_timestamp_to_seconds(
                timestamp
            )
        )

        chapters.append(
            TutorialChapter(
                start_seconds=start_seconds,
                title=title,
            )
        )

    if len(chapters) < 3:
        return []

    if chapters[0].start_seconds != 0:
        return []

    for previous, current in zip(
        chapters,
        chapters[1:],
    ):
        if (
            current.start_seconds
            <= previous.start_seconds
        ):
            return []

    return chapters

def parse_youtube_duration(duration: str,) -> int:
    pattern = re.compile(
        r"^PT"
        r"(?:(\d+)H)?"
        r"(?:(\d+)M)?"
        r"(?:(\d+)S)?$"
    )

    match = pattern.match(duration)

    if match is None:
        raise ValueError(
            "Invalid YouTube video duration."
        )

    hours = int(match.group(1) or 0)
    minutes = int(match.group(2) or 0)
    seconds = int(match.group(3) or 0)

    return (hours * 3600+ minutes * 60+ seconds)

def optional_int(value: str | None,) -> int | None:
    if value is None:
        return None
    try:
        return int(value)
    except (TypeError, ValueError):
        return None

def is_english_audio_language(language_code: str | None,) -> bool:
    if language_code is None:
        return True

    normalized_code = (
        language_code
        .strip()
        .lower()
    )

    return (
        normalized_code == "en"
        or normalized_code.startswith(
            "en-"
        )
    )

def fetch_video_metadata(
    video_ids: list[str],
) -> list[TutorialVideo]:

    if not video_ids:
        return []

    unique_video_ids = list(
        dict.fromkeys(video_ids)
    )

    if len(unique_video_ids) > 50:
        raise ValueError(
            "A maximum of 50 video IDs can be "
            "requested at once."
        )

    params = {
        "part": (
            "snippet,"
            "contentDetails,"
            "statistics,"
            "status"
        ),
        "id": ",".join(
            unique_video_ids
        ),
        "key": get_youtube_api_key(),
    }

    try:
        response = requests.get(
            YOUTUBE_VIDEOS_URL,
            params=params,
            timeout=REQUEST_TIMEOUT_SECONDS,
        )

        response.raise_for_status()

        data = response.json()

    except requests.RequestException as exc:
        raise RuntimeError(
            "YouTube video metadata request "
            f"failed: {exc.__class__.__name__}."
        ) from None

    videos = []

    for item in data.get(
        "items",
        [],
    ):
        video_id = item.get(
            "id"
        )

        snippet = item.get(
            "snippet",
            {},
        )

        default_audio_language = (
            snippet.get(
                "defaultAudioLanguage"
            )
        )

        if not is_english_audio_language(
            default_audio_language
        ):
            continue

        content_details = item.get(
            "contentDetails",
            {},
        )

        statistics = item.get(
            "statistics",
            {},
        )

        status = item.get(
            "status",
            {},
        )

        if (
            status.get("privacyStatus")
            != "public"
        ):
            continue

        title = snippet.get(
            "title",
            "",
        ).strip()

        description = snippet.get(
            "description",
            "",
        ).strip()

        channel_name = snippet.get(
            "channelTitle",
            "",
        ).strip()

        published_at = snippet.get(
            "publishedAt",
            "",
        ).strip()

        duration = content_details.get(
            "duration"
        )

        if (
            not video_id
            or not title
            or not channel_name
            or not published_at
            or not duration
        ):
            continue

        duration_seconds = (
            parse_youtube_duration(
                duration
            )
        )

        captions_available = (
            content_details.get(
                "caption"
            )
            == "true"
        )

        chapters = (
            extract_video_chapters(
                description
            )
        )

        videos.append(
            TutorialVideo(
                video_id=video_id,
                title=title,
                description=(
                    description
                    or None
                ),
                url=(
                    "https://www.youtube.com/"
                    f"watch?v={video_id}"
                ),
                channel_name=channel_name,
                published_at=published_at,
                duration_seconds=(
                    duration_seconds
                ),
                captions_available=(
                    captions_available
                ),
                view_count=optional_int(
                    statistics.get(
                        "viewCount"
                    )
                ),
                like_count=optional_int(
                    statistics.get(
                        "likeCount"
                    )
                ),
                comment_count=optional_int(
                    statistics.get(
                        "commentCount"
                    )
                ),
                chapters=chapters,
            )
        )

    return videos