import mimetypes
import os
import subprocess
import sys
import tempfile
from pathlib import Path
from typing import Any

import requests

from learning_workflow.tools.youtube_transcript import (
    sample_transcript_text,
)


YOUTUBE_TRANSCRIPT_AI_URL = (
    "https://youtube-transcript.ai/"
    "transcript/{video_id}.txt"
)

GROQ_TRANSCRIPTION_URL = (
    "https://api.groq.com/"
    "openai/v1/audio/transcriptions"
)

GROQ_WHISPER_MODEL = (
    "whisper-large-v3-turbo"
)

LOCAL_WHISPER_MODEL = "small.en"


# =====================================================================
# EXISTING TUTORIAL AGENT TRANSCRIPT
# =====================================================================

def get_existing_tutorial_transcript(
    existing_transcript: dict[str, Any] | None,
) -> dict[str, Any] | None:

    if not existing_transcript:
        return None

    short_text = existing_transcript.get(
        "text"
    )

    full_text = existing_transcript.get(
        "full_text"
    )

    if (
        not isinstance(short_text, str)
        or not short_text.strip()
    ):
        short_text = None

    else:
        short_text = short_text.strip()

    if (
        not isinstance(full_text, str)
        or not full_text.strip()
    ):
        full_text = None

    else:
        full_text = full_text.strip()

    if (
        short_text is None
        and full_text is None
    ):
        return None

    if (
        short_text is None
        and full_text is not None
    ):
        short_text = (
            sample_transcript_text(
                full_text
            )
        )

    return {
        "source": "tutorial_agent",
        "short_text": short_text,
        "full_text": full_text,
        "short_text_char_count": (
            len(short_text)
            if short_text is not None
            else 0
        ),
        "full_text_char_count": (
            len(full_text)
            if full_text is not None
            else 0
        ),
    }


# =====================================================================
# FREE CAPTION API
# =====================================================================

def fetch_youtube_transcript_ai(
    video_id: str,
) -> dict[str, Any] | None:

    try:
        response = requests.get(
            YOUTUBE_TRANSCRIPT_AI_URL.format(
                video_id=video_id
            ),
            params={
                "lang": "en",
            },
            timeout=30,
        )

    except requests.RequestException:
        return None

    if response.status_code != 200:
        return None

    full_text = response.text.strip()

    if not full_text:
        return None

    short_text = (
        sample_transcript_text(
            full_text
        )
    )

    return {
        "source": "youtube_transcript_ai",
        "short_text": short_text,
        "full_text": full_text,
        "short_text_char_count": len(
            short_text
        ),
        "full_text_char_count": len(
            full_text
        ),
    }


# =====================================================================
# TEMPORARY YOUTUBE AUDIO
# =====================================================================

def download_youtube_audio(
    video_id: str,
    output_directory: str,
) -> Path | None:

    video_url = (
        "https://www.youtube.com/watch?v="
        f"{video_id}"
    )

    output_template = str(
        Path(output_directory)
        / "%(id)s.%(ext)s"
    )

    command = [
        sys.executable,
        "-m",
        "yt_dlp",
        "--no-playlist",
        "-f",
        (
            "bestaudio[abr<=64]/"
            "bestaudio/best"
        ),
        "-o",
        output_template,
        video_url,
    ]

    try:
        subprocess.run(
            command,
            check=True,
            capture_output=True,
            text=True,
            timeout=600,
        )

    except (
        subprocess.SubprocessError,
        OSError,
    ):
        return None

    audio_files = [
        path
        for path in Path(
            output_directory
        ).iterdir()
        if path.is_file()
    ]

    if not audio_files:
        return None

    return audio_files[0]


# =====================================================================
# GROQ WHISPER
# =====================================================================

def transcribe_with_groq(
    audio_path: Path,
) -> dict[str, Any] | None:

    groq_api_key = os.getenv(
        "Groq_API_KEY"
    )

    if not groq_api_key:
        return None

    mime_type = (
        mimetypes.guess_type(
            audio_path.name
        )[0]
        or "application/octet-stream"
    )

    try:
        with audio_path.open(
            "rb"
        ) as audio_file:

            response = requests.post(
                GROQ_TRANSCRIPTION_URL,
                headers={
                    "Authorization": (
                        "Bearer "
                        f"{groq_api_key}"
                    ),
                },
                files={
                    "file": (
                        audio_path.name,
                        audio_file,
                        mime_type,
                    ),
                },
                data={
                    "model": (
                        GROQ_WHISPER_MODEL
                    ),
                    "language": "en",
                    "response_format": "json",
                    "temperature": "0",
                },
                timeout=600,
            )

    except (
        requests.RequestException,
        OSError,
    ):
        return None

    if response.status_code != 200:
        return None

    try:
        response_data = response.json()

    except ValueError:
        return None

    full_text = response_data.get(
        "text"
    )

    if (
        not isinstance(full_text, str)
        or not full_text.strip()
    ):
        return None

    full_text = full_text.strip()

    short_text = (
        sample_transcript_text(
            full_text
        )
    )

    return {
        "source": "groq_whisper",
        "short_text": short_text,
        "full_text": full_text,
        "short_text_char_count": len(
            short_text
        ),
        "full_text_char_count": len(
            full_text
        ),
    }


# =====================================================================
# LOCAL WHISPER
# =====================================================================

def transcribe_with_local_whisper(
    audio_path: Path,
) -> dict[str, Any] | None:

    try:
        from faster_whisper import (
            WhisperModel,
        )

    except ImportError:
        return None

    try:
        model = WhisperModel(
            LOCAL_WHISPER_MODEL,
            device="cpu",
            compute_type="int8",
        )

        segments, _ = model.transcribe(
            str(audio_path),
            language="en",
            vad_filter=True,
        )

        text_parts: list[str] = []

        for segment in segments:
            segment_text = (
                segment.text.strip()
            )

            if segment_text:
                text_parts.append(
                    segment_text
                )

        full_text = " ".join(
            text_parts
        ).strip()

    except Exception:
        return None

    if not full_text:
        return None

    short_text = (
        sample_transcript_text(
            full_text
        )
    )

    return {
        "source": "local_whisper",
        "short_text": short_text,
        "full_text": full_text,
        "short_text_char_count": len(
            short_text
        ),
        "full_text_char_count": len(
            full_text
        ),
    }


# =====================================================================
# STANDALONE VIDEO TRANSCRIPT RESOLVER
# =====================================================================

def resolve_standalone_quiz_transcript(
    video_id: str,
    existing_transcript: dict[str, Any] | None,
) -> dict[str, Any] | None:

    existing_result = (
        get_existing_tutorial_transcript(
            existing_transcript
        )
    )

    if (
        existing_result is not None
        and existing_result[
            "full_text"
        ] is not None
    ):
        return existing_result

    existing_short_text = None

    if existing_result is not None:
        existing_short_text = (
            existing_result[
                "short_text"
            ]
        )

    transcript = (
        fetch_youtube_transcript_ai(
            video_id
        )
    )

    if transcript is not None:

        if existing_short_text is not None:
            transcript[
                "short_text"
            ] = existing_short_text

            transcript[
                "short_text_char_count"
            ] = len(
                existing_short_text
            )

        return transcript

    with tempfile.TemporaryDirectory() as temp_dir:

        audio_path = (
            download_youtube_audio(
                video_id,
                temp_dir,
            )
        )

        if audio_path is not None:

            transcript = (
                transcribe_with_groq(
                    audio_path
                )
            )

            if transcript is not None:

                if existing_short_text is not None:
                    transcript[
                        "short_text"
                    ] = existing_short_text

                    transcript[
                        "short_text_char_count"
                    ] = len(
                        existing_short_text
                    )

                return transcript

            transcript = (
                transcribe_with_local_whisper(
                    audio_path
                )
            )

            if transcript is not None:

                if existing_short_text is not None:
                    transcript[
                        "short_text"
                    ] = existing_short_text

                    transcript[
                        "short_text_char_count"
                    ] = len(
                        existing_short_text
                    )

                return transcript

    return existing_result