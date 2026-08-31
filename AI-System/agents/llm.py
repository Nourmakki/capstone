import os
from time import sleep
from typing import Any
from dotenv import load_dotenv
from langchain_google_genai import (ChatGoogleGenerativeAI,)
from langchain_openai import ChatOpenAI


load_dotenv()


GEMINI_RETRY_DELAYS = (
    5,
    15,
)


def get_gemini():
    """
    Return the primary Gemini model.
    """
    gemini_key = os.getenv(
        "GEMINI_API_KEY"
    )

    if not gemini_key:
        raise ValueError(
            "GEMINI_API_KEY is missing."
        )

    return ChatGoogleGenerativeAI(
        model="gemini-3.1-flash-lite",
        api_key=gemini_key,
        temperature=0,
        max_tokens=None,
        timeout=60,
        max_retries=2,
    )


def get_groq():
    """
    Return the defensive Groq fallback model.
    """
    groq_key = os.getenv(
        "Groq_API_KEY"
    )

    if not groq_key:
        raise ValueError(
            "Groq_API_KEY is missing."
        )

    return ChatOpenAI(
        model="openai/gpt-oss-20b",
        api_key=groq_key,
        base_url=(
            "https://api.groq.com/openai/v1"
        ),
        temperature=0,
        timeout=60,
        max_retries=2,
    )


def is_transient_llm_error(
    error: Exception,
) -> bool:
    message = str(
        error
    ).casefold()

    transient_terms = (
        "429",
        "503",
        "resource exhausted",
        "rate limit",
        "rate_limit",
        "unavailable",
        "service unavailable",
        "temporarily unavailable",
        "high demand",
        "timeout",
        "timed out",
        "connection error",
        "connection reset",
    )

    return any(
        term in message
        for term in transient_terms
    )


gemini_llm = get_gemini()

try:
    groq_llm = get_groq()
except ValueError:
    groq_llm = None


def invoke_llm(
    messages: Any,
    *args,
    **kwargs,
):
    """
    Use Gemini as primary.

    Retry temporary Gemini failures.

    If Gemini remains unavailable,
    use Groq as fallback.
    """
    last_gemini_error: Exception | None = (
        None
    )

    total_gemini_attempts = (
        len(
            GEMINI_RETRY_DELAYS
        )
        + 1
    )

    for attempt in range(
        total_gemini_attempts
    ):
        try:
            return gemini_llm.invoke(
                messages,
                *args,
                **kwargs,
            )

        except Exception as error:
            if not is_transient_llm_error(
                error
            ):
                raise

            last_gemini_error = error

            is_last_attempt = (
                attempt
                == total_gemini_attempts - 1
            )

            if is_last_attempt:
                break

            sleep(
                GEMINI_RETRY_DELAYS[
                    attempt
                ]
            )

    if groq_llm is None:
        raise RuntimeError(
            "Gemini remained unavailable and "
            "Groq fallback is not configured. "
            f"Last Gemini error: "
            f"{last_gemini_error}"
        ) from last_gemini_error

    try:
        return groq_llm.invoke(
            messages,
            *args,
            **kwargs,
        )

    except Exception as groq_error:
        raise RuntimeError(
            "Both Gemini and Groq failed. "
            f"Last Gemini error: "
            f"{last_gemini_error}. "
            f"Groq error: "
            f"{groq_error}"
        ) from groq_error


class ResilientLLM:
    """
    Compatibility wrapper.

    Existing agents can continue using:

        from agents.llm import llm
        llm.invoke(...)

    while automatically receiving the
    Gemini -> retry -> Groq fallback logic.
    """

    def invoke(
        self,
        messages: Any,
        *args,
        **kwargs,
    ):
        return invoke_llm(
            messages,
            *args,
            **kwargs,
        )

    def __getattr__(
        self,
        name: str,
    ):
        return getattr(
            gemini_llm,
            name,
        )


llm = ResilientLLM()