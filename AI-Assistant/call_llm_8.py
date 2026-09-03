"""STEP 8: Ask the model.

Gemini, on this project's own key - separate from AI-System's, so a burst of
roadmap generation cannot start failing the assistant's answers by exhausting
a shared rate limit.

Two models, in order. gemini-flash-latest answers better; it also returned 503
UNAVAILABLE on one of three test calls, which is overload rather than error,
and retrying is the difference between an answer and an apology. Falling back
to the lite model beats failing, and a student cannot tell which one wrote a
three-sentence answer about their roadmap.

gemini-2.5-flash is deliberately absent: it 404s for keys created recently,
so naming it would work on an old key and break on a new one.
"""

import os
import time

from dotenv import load_dotenv
from google import genai
from google.genai import types

load_dotenv()

MODELS = ["gemini-flash-latest", "gemini-flash-lite-latest"]

_client = None


def get_client() -> genai.Client:
    global _client

    if _client is None:
        key = os.getenv("GEMINI_API_KEY", "").strip()

        if not key:
            raise ValueError("GEMINI_API_KEY is missing from .env.")

        _client = genai.Client(api_key=key)

    return _client


def generate_answer(prompt: str) -> str:
    client = get_client()
    last_error: Exception | None = None

    for model in MODELS:
        for attempt in range(2):
            try:
                response = client.models.generate_content(
                    model=model,
                    contents=prompt,
                    config=types.GenerateContentConfig(
                        # Zero: this answers from retrieved facts, and there is
                        # nothing here that creativity improves.
                        temperature=0,
                        max_output_tokens=600,
                    ),
                )

                if text := (response.text or "").strip():
                    return text

                # An empty body is usually a safety block. Another model will
                # not fix it, so stop rather than burn the fallback on it.
                return (
                    "I could not produce an answer for that. Try rephrasing "
                    "the question, or ask about a specific roadmap or module."
                )

            except Exception as error:  # noqa: BLE001 - reported, not swallowed
                last_error = error

                # 503 is the model being busy, and it clears in seconds.
                if "503" in str(error) or "UNAVAILABLE" in str(error):
                    time.sleep(2)
                    continue

                break

    raise RuntimeError(f"Every model failed. Last error: {last_error}")
