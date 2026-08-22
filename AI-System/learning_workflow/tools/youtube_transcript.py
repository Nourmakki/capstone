from youtube_transcript_api import YouTubeTranscriptApi

_ytt_api = YouTubeTranscriptApi()

MAX_TRANSCRIPT_CHARS_PER_VIDEO = 6000
TRANSCRIPT_SAMPLE_SEPARATOR = "\n...\n"


def sample_transcript_text(
    text: str,
    max_chars: int = MAX_TRANSCRIPT_CHARS_PER_VIDEO,
) -> str:
    clean_text = " ".join(
        text.split()
    )

    if len(clean_text) <= max_chars:
        return clean_text

    separator_length = len(
        TRANSCRIPT_SAMPLE_SEPARATOR
    )

    section_budget = (
        max_chars
        - (2 * separator_length)
    ) // 3

    first_end = (
        len(clean_text) // 3
    )

    second_end = (
        len(clean_text) * 2 // 3
    )

    beginning_section = (
        clean_text[:first_end]
    )

    middle_section = (
        clean_text[
            first_end:second_end
        ]
    )

    ending_section = (
        clean_text[second_end:]
    )

    beginning_sample = (
        beginning_section[
            :section_budget
        ].strip()
    )

    if (
        len(middle_section)
        <= section_budget
    ):
        middle_sample = (
            middle_section.strip()
        )

    else:
        middle_start = (
            len(middle_section)
            - section_budget
        ) // 2

        middle_sample = (
            middle_section[
                middle_start:
                middle_start
                + section_budget
            ].strip()
        )

    ending_sample = (
        ending_section[
            -section_budget:
        ].strip()
    )

    return (
        TRANSCRIPT_SAMPLE_SEPARATOR.join(
            [
                beginning_sample,
                middle_sample,
                ending_sample,
            ]
        )
    )


def fetch_video_transcript(
    video_id: str,
    languages: list[str] | None = None,
) -> dict | None:
    if not video_id:
        raise ValueError(
            "video_id is required"
        )

    preferred_languages = (
        languages or ["en"]
    )

    try:
        transcript = _ytt_api.fetch(
            video_id,
            languages=preferred_languages,
        )

    except Exception:
        return None

    full_text = " ".join(
        snippet.text.strip()
        for snippet in transcript
        if (
            snippet.text
            and snippet.text.strip()
        )
    )

    if not full_text:
        return None

    sampled_text = (
        sample_transcript_text(
            full_text
        )
    )

    return {
        "video_id": video_id,
        "text": sampled_text,
        "language_code": (
            transcript.language_code
        ),
        "is_generated": (
            transcript.is_generated
        ),
        "is_sampled": (
            len(sampled_text)
            < len(full_text)
        ),
        "original_char_count": (
            len(full_text)
        ),
        "sampled_char_count": (
            len(sampled_text)
        ),
    }