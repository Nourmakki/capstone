import os
import json
import re
import requests

from datetime import (
    date,
    datetime,
    timedelta,
)
from typing import Any

from dotenv import load_dotenv

from agents.llm import llm


load_dotenv()


# =====================================================================
# CONFIGURATION
# =====================================================================

SERPER_SEARCH_URL = (
    "https://google.serper.dev/search"
)

REQUEST_TIMEOUT_SECONDS = 20

MAX_SEARCH_RESULTS = 15

MAX_SELECTED_WORKSHOPS = 6


# =====================================================================
# CURRENT TIME
# =====================================================================

def get_current_datetime() -> datetime:
    """
    Return the actual current local datetime.

    The Workshop Agent needs the real current date so that:
    - search queries use the correct month/year
    - past workshops are rejected
    """

    return datetime.now().astimezone()


# =====================================================================
# SERPER API KEY
# =====================================================================

def get_workshop_serper_api_key() -> str:
    """
    Read the Workshop-specific Serper API key from .env.

    A separate environment variable is used so this workflow does not
    depend on any Serper key used by another workflow.
    """

    api_key = os.getenv(
        "WORKSHOP_SERPER_API_KEY"
    )

    if not api_key:
        raise ValueError(
            "WORKSHOP_SERPER_API_KEY "
            "is not configured."
        )

    return api_key


# =====================================================================
# DATE LABELS FOR SEARCH
# =====================================================================

def get_month_label(
    current_datetime: datetime,
    month_offset: int = 0,
) -> str:
    """
    Return a month and year such as:

        September 2026

    month_offset=0:
        current month

    month_offset=1:
        next month

    This also handles year changes correctly.
    Example:
        December 2026 + 1 month
        -> January 2027
    """

    month_index = (
        current_datetime.month
        - 1
        + month_offset
    )

    year = (
        current_datetime.year
        + month_index // 12
    )

    month = (
        month_index % 12
        + 1
    )

    return date(
        year,
        month,
        1,
    ).strftime(
        "%B %Y"
    )


# =====================================================================
# SEARCH QUERY
# =====================================================================

def build_workshop_search_query(
    roadmap_topic: str,
    student_location: str | None,
    regeneration_feedback: str | None = None,
) -> str:
    """
    Build one simple Serper search query.

    We intentionally keep the query short because Google
    already performs semantic matching.

    One Workshop Agent run always produces one search query
    and therefore consumes one Serper request.
    """

    topic = roadmap_topic.strip()

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    now = get_current_datetime()

    current_month = get_month_label(
        now,
        0,
    )

    if (
        student_location
        and student_location.strip()
    ):
        # Use the main locality for search.
        #
        # Example:
        # "Beirut, Lebanon"
        # -> "Beirut"
        location_label = (
            student_location
            .split(",")[0]
            .strip()
        )

        query = (
            f"{topic} workshop "
            f"{location_label} "
            f"{current_month}"
        )

    else:
        query = (
            f"{topic} online workshop "
            f"{current_month}"
        )

    return query
# =====================================================================
# DATE PARSING
# =====================================================================

MONTH_NAME_PATTERN = (
    r"(?:"
    r"Jan(?:uary)?|"
    r"Feb(?:ruary)?|"
    r"Mar(?:ch)?|"
    r"Apr(?:il)?|"
    r"May|"
    r"Jun(?:e)?|"
    r"Jul(?:y)?|"
    r"Aug(?:ust)?|"
    r"Sep(?:tember)?|"
    r"Oct(?:ober)?|"
    r"Nov(?:ember)?|"
    r"Dec(?:ember)?"
    r")"
)


def parse_month_number(
    month_text: str,
) -> int | None:
    """
    Convert a month name such as:

        September
        Sep

    into:

        9

    Returns None when the text is not a valid month.
    """

    for format_string in (
        "%B",
        "%b",
    ):
        try:
            return datetime.strptime(
                month_text,
                format_string,
            ).month

        except ValueError:
            continue

    return None


def build_future_date(
    year: int,
    month: int,
    day: int,
    current_date: date,
) -> date | None:
    """
    Safely construct a calendar date.

    Invalid dates are rejected.

    Example:
        February 31
        -> None

    Past dates are also rejected.

    Example:
        current date = September 5, 2026
        workshop = March 10, 2026
        -> None
    """

    try:
        parsed_date = date(
            year,
            month,
            day,
        )

    except ValueError:
        return None

    if parsed_date < current_date:
        return None

    return parsed_date


def extract_future_event_date(
    text: str,
    current_date: date,
) -> str | None:
    """
    Extract a real current/future event date from the search result.

    Supported examples include:

        September 12, 2026
        Sep 12, 2026
        12 September 2026
        12th September 2026
        19th - 20th September 2026
        September 19-20, 2026
        2026-09-12
        Today
        Tomorrow

    For ranges, the event start date is returned.

    IMPORTANT:
    We parse only the event evidence from the title/snippet.

    We do NOT use Google's/Serper's result "date" field because that may
    represent when a Facebook post or article was published rather than
    when the workshop happens.

    If no future event date can be verified, the candidate is rejected
    before it reaches the LLM.
    """

    if not text:
        return None

    candidates: list[date] = []

    lower_text = text.lower()

    # ---------------------------------------------------------------
    # Today
    # ---------------------------------------------------------------

    if re.search(
        r"\btoday\b",
        lower_text,
    ):
        candidates.append(
            current_date
        )

    # ---------------------------------------------------------------
    # Tomorrow
    # ---------------------------------------------------------------

    if re.search(
        r"\btomorrow\b",
        lower_text,
    ):
        candidates.append(
            current_date
            + timedelta(days=1)
        )

    # ---------------------------------------------------------------
    # ISO:
    # 2026-09-12
    # ---------------------------------------------------------------

    for match in re.finditer(
        r"\b"
        r"(\d{4})"
        r"-"
        r"(\d{2})"
        r"-"
        r"(\d{2})"
        r"\b",
        text,
    ):
        parsed_date = build_future_date(
            year=int(
                match.group(1)
            ),
            month=int(
                match.group(2)
            ),
            day=int(
                match.group(3)
            ),
            current_date=current_date,
        )

        if parsed_date:
            candidates.append(
                parsed_date
            )

    # ---------------------------------------------------------------
    # 19th - 20th September 2026
    # ---------------------------------------------------------------

    day_range_pattern = (
        rf"\b"
        rf"(\d{{1,2}})"
        rf"(?:st|nd|rd|th)?"
        rf"\s*[-–—]\s*"
        rf"(\d{{1,2}})"
        rf"(?:st|nd|rd|th)?"
        rf"\s+"
        rf"({MONTH_NAME_PATTERN})"
        rf"(?:,\s*|\s+)"
        rf"(\d{{4}})"
        rf"\b"
    )

    for match in re.finditer(
        day_range_pattern,
        text,
        re.IGNORECASE,
    ):
        month_number = (
            parse_month_number(
                match.group(3)
            )
        )

        if month_number is None:
            continue

        parsed_date = build_future_date(
            year=int(
                match.group(4)
            ),
            month=month_number,
            day=int(
                match.group(1)
            ),
            current_date=current_date,
        )

        if parsed_date:
            candidates.append(
                parsed_date
            )

    # ---------------------------------------------------------------
    # September 19-20, 2026
    # ---------------------------------------------------------------

    month_range_pattern = (
        rf"\b"
        rf"({MONTH_NAME_PATTERN})"
        rf"\s+"
        rf"(\d{{1,2}})"
        rf"(?:st|nd|rd|th)?"
        rf"\s*[-–—]\s*"
        rf"(\d{{1,2}})"
        rf"(?:st|nd|rd|th)?"
        rf"(?:,\s*|\s+)"
        rf"(\d{{4}})"
        rf"\b"
    )

    for match in re.finditer(
        month_range_pattern,
        text,
        re.IGNORECASE,
    ):
        month_number = (
            parse_month_number(
                match.group(1)
            )
        )

        if month_number is None:
            continue

        parsed_date = build_future_date(
            year=int(
                match.group(4)
            ),
            month=month_number,
            day=int(
                match.group(2)
            ),
            current_date=current_date,
        )

        if parsed_date:
            candidates.append(
                parsed_date
            )

    # ---------------------------------------------------------------
    # September 12, 2026
    # September 12
    # ---------------------------------------------------------------

    month_first_pattern = (
        rf"\b"
        rf"({MONTH_NAME_PATTERN})"
        rf"\s+"
        rf"(\d{{1,2}})"
        rf"(?:st|nd|rd|th)?"
        rf"(?:,\s*|\s+)?"
        rf"(\d{{4}})?"
        rf"\b"
    )

    for match in re.finditer(
        month_first_pattern,
        text,
        re.IGNORECASE,
    ):
        month_number = (
            parse_month_number(
                match.group(1)
            )
        )

        if month_number is None:
            continue

        year = (
            int(match.group(3))
            if match.group(3)
            else current_date.year
        )

        parsed_date = build_future_date(
            year=year,
            month=month_number,
            day=int(
                match.group(2)
            ),
            current_date=current_date,
        )

        if parsed_date:
            candidates.append(
                parsed_date
            )

    # ---------------------------------------------------------------
    # 12 September 2026
    # 12th September 2026
    # ---------------------------------------------------------------

    day_first_pattern = (
        rf"\b"
        rf"(\d{{1,2}})"
        rf"(?:st|nd|rd|th)?"
        rf"\s+"
        rf"({MONTH_NAME_PATTERN})"
        rf"(?:,\s*|\s+)?"
        rf"(\d{{4}})?"
        rf"\b"
    )

    for match in re.finditer(
        day_first_pattern,
        text,
        re.IGNORECASE,
    ):
        month_number = (
            parse_month_number(
                match.group(2)
            )
        )

        if month_number is None:
            continue

        year = (
            int(match.group(3))
            if match.group(3)
            else current_date.year
        )

        parsed_date = build_future_date(
            year=year,
            month=month_number,
            day=int(
                match.group(1)
            ),
            current_date=current_date,
        )

        if parsed_date:
            candidates.append(
                parsed_date
            )

    if not candidates:
        return None

    return min(
        candidates
    ).isoformat()


# =====================================================================
# TIME EXTRACTION
# =====================================================================

def extract_event_time(
    text: str,
) -> str | None:
    """
    Extract a visible workshop time when Serper's title/snippet
    contains one.

    Examples:

        7:00 PM
        18:00
        18:00–22:00
        7:00 PM - 9:00 PM

    If no time is visible, return None.

    Time is optional in our output because search snippets do not always
    expose it.
    """

    patterns = [
        (
            r"\b"
            r"\d{1,2}:\d{2}"
            r"\s*"
            r"(?:AM|PM|am|pm)"
            r"(?:"
            r"\s*[-–—]\s*"
            r"\d{1,2}:\d{2}"
            r"\s*"
            r"(?:AM|PM|am|pm)"
            r")?"
            r"\b"
        ),
        (
            r"\b"
            r"\d{1,2}"
            r"\s*"
            r"(?:AM|PM|am|pm)"
            r"(?:"
            r"\s*[-–—]\s*"
            r"\d{1,2}"
            r"\s*"
            r"(?:AM|PM|am|pm)"
            r")?"
            r"\b"
        ),
        (
            r"\b"
            r"\d{1,2}:\d{2}"
            r"\s*[-–—]\s*"
            r"\d{1,2}:\d{2}"
            r"\b"
        ),
        (
            r"\b"
            r"\d{1,2}:\d{2}"
            r"\b"
        ),
    ]

    for pattern in patterns:
        match = re.search(
            pattern,
            text,
        )

        if match:
            return (
                match.group(0)
                .strip()
            )

    return None


# =====================================================================
# ONLINE DETECTION
# =====================================================================

def is_online_event(
    text: str,
) -> bool:
    """
    Detect whether the event is clearly online.

    The event is considered online only when the search evidence contains
    terms such as:
    - online
    - virtual
    - Zoom
    - webinar
    - remote event
    """

    lower_text = text.lower()

    online_terms = (
        "online",
        "virtual",
        "zoom",
        "webinar",
        "remote event",
        "livestream",
        "live stream",
    )

    return any(
        term in lower_text
        for term in online_terms
    )


# =====================================================================
# LOCATION EVIDENCE
# =====================================================================

def contains_student_location(
    text: str,
    student_location: str,
) -> bool:
    """
    Check whether the search result contains evidence related to the
    student's location.

    Example:

        student_location = "Beirut, Lebanon"

    A result mentioning either:
        Beirut
        Lebanon

    may be considered a local candidate.

    This intentionally allows nearby events such as Byblos, Lebanon to
    remain candidates instead of requiring the exact city name.
    """

    location_parts = [
        part.strip().lower()
        for part in re.split(
            r"[,/]",
            student_location,
        )
        if len(
            part.strip()
        ) >= 3
    ]

    lower_text = text.lower()

    return any(
        part in lower_text
        for part in location_parts
    )


# =====================================================================
# COST EXTRACTION
# =====================================================================

def extract_event_cost(
    text: str,
) -> tuple[
    str,
    str | None,
]:
    """
    Extract cost information when it is clearly present.

    Returns:

        ("free", "Free")
        ("paid", "$20")
        ("not_specified", None)

    We do NOT assume an event is free when no price is shown.
    """

    free_patterns = [
        (
            r"\bfree\s+"
            r"(?:"
            r"workshop|event|webinar|training|"
            r"seminar|session|conference|"
            r"admission|entry|registration|ticket|tickets"
            r")\b"
        ),
        (
            r"\b"
            r"(?:admission|entry|registration|tickets?)"
            r"\s+(?:is\s+|are\s+)?free\b"
        ),
    ]

    for pattern in free_patterns:
        if re.search(
            pattern,
            text,
            re.IGNORECASE,
        ):
            return (
                "free",
                "Free",
            )

    currency_patterns = [
        r"\$\s?\d+(?:\.\d{1,2})?",
        r"\bUSD\s?\d+(?:\.\d{1,2})?",
        r"€\s?\d+(?:\.\d{1,2})?",
        r"\bEUR\s?\d+(?:\.\d{1,2})?",
        r"\bLBP\s?[\d,]+",
    ]

    for pattern in currency_patterns:
        match = re.search(
            pattern,
            text,
            re.IGNORECASE,
        )

        if match:
            return (
                "paid",
                match.group(0).strip(),
            )

    return (
        "not_specified",
        None,
    )


# =====================================================================
# NORMALIZE TEXT FOR VALIDATION
# =====================================================================

def normalize_matching_text(
    value: str,
) -> str:
    """
    Simplify text for evidence comparison.

    Example:

        "BDD 1280, Beirut"
        -> "bdd 1280 beirut"

    This makes validation less sensitive to commas or punctuation.
    """

    normalized = re.sub(
        r"[^a-zA-Z0-9]+",
        " ",
        value.lower(),
    )

    return re.sub(
        r"\s+",
        " ",
        normalized,
    ).strip()


def text_is_supported_by_evidence(
    value: str,
    evidence: str,
) -> bool:
    """
    Verify that an LLM-produced factual value is actually supported by
    the Serper search evidence.

    Direct substring matching is attempted first.

    If punctuation/wording differs, meaningful tokens are checked.

    Example:

        selected location:
            "BDD 1280, Beirut"

        evidence:
            "at BDD 1280 in Beirut"

    This still passes because the meaningful tokens exist in evidence.
    """

    normalized_value = (
        normalize_matching_text(
            value
        )
    )

    normalized_evidence = (
        normalize_matching_text(
            evidence
        )
    )

    if not normalized_value:
        return False

    if (
        normalized_value
        in normalized_evidence
    ):
        return True

    value_tokens = [
        token
        for token
        in normalized_value.split()
        if len(token) >= 3
        or token.isdigit()
    ]

    if not value_tokens:
        return False

    evidence_tokens = set(
        normalized_evidence.split()
    )

    return all(
        token in evidence_tokens
        for token in value_tokens
    )


# =====================================================================
# NORMALIZE ONE SERPER RESULT
# =====================================================================

def normalize_serper_candidate(
    result: dict[str, Any],
    current_date: date,
    student_location: str | None,
) -> dict[str, Any] | None:
    """
    Convert one raw Serper organic result into a usable workshop candidate.

    A candidate is rejected BEFORE reaching the LLM when it does not have:

    - title
    - real HTTP link
    - verifiable current/future event date
    - location evidence

    Location handling means:
    - clearly online candidates remain eligible
    OR
    - physical candidates remain eligible when the student supplied a
      location, so the LLM can evaluate geographic containment (for
      example, whether a city is inside the requested country)

    Python still requires the selected physical location to be supported
    by the candidate evidence during final output validation.

    Organizer, speaker, exact time, and price remain optional.
    """

    title = str(
        result.get(
            "title",
            "",
        )
    ).strip()

    link = str(
        result.get(
            "link",
            "",
        )
    ).strip()

    snippet = str(
        result.get(
            "snippet",
            "",
        )
    ).strip()

    source = str(
        result.get(
            "source",
            "",
        )
    ).strip()

    if (
        not title
        or not link.startswith(
            "http"
        )
    ):
        return None

    # Important:
    # result["date"] is deliberately NOT included.
    #
    # It may represent publication/post date instead of event date.
    evidence = " ".join(
        value
        for value in (
            title,
            snippet,
            source,
        )
        if value
    ).strip()

    event_date = (
        extract_future_event_date(
            evidence,
            current_date,
        )
    )

    # No verifiable event date:
    # do not give this candidate to the LLM.
    if event_date is None:
        return None

    online = is_online_event(
        evidence
    )

    if online:
        location_hint = "Online"

    else:
        # If the student has no location, only online events
        # should be returned.
        if not student_location:
            return None

        # Do not discard a candidate only because its evidence names a
        # city while the student supplied the containing country. The LLM
        # evaluates that geographic relationship. However, only expose the
        # student's location as a safe fallback when it already appears
        # literally in the evidence. This prevents an unsupported or
        # invented location from passing final validation.
        literal_location_match = contains_student_location(
            evidence,
            student_location,
        )

        location_hint = (
            student_location
            if literal_location_match
            else None
        )

    event_time = extract_event_time(
        evidence
    )

    cost_type, price = (
        extract_event_cost(
            evidence
        )
    )

    return {
        "search_title": title,
        "link": link,
        "snippet": snippet,
        "source": source,

        "event_date": event_date,
        "event_time": event_time,

        "location_hint": (
            location_hint
        ),

        "cost_type": cost_type,
        "price": price,

        "evidence": evidence,
    }


# =====================================================================
# SERPER SEARCH
# =====================================================================

def search_workshop_candidates(
    roadmap_topic: str,
    student_location: str | None = None,
    regeneration_feedback: str | None = None,
) -> list[dict[str, Any]]:
    """
    Perform exactly ONE Serper web-search request.

    The function:

    1. Builds one broad query.
    2. Sends one POST request to Serper.
    3. Reads organic Google results.
    4. Normalizes each result.
    5. Rejects incomplete/past results.
    6. Removes duplicate links.
    7. Returns at most 15 valid candidates.

    The function does NOT decide which workshop is best.

    Relevance ranking is left to the LLM.
    """

    query = build_workshop_search_query(
        roadmap_topic=roadmap_topic,
        student_location=student_location,
        regeneration_feedback=(
            regeneration_feedback
        ),
    )

    headers = {
        "X-API-KEY": (
            get_workshop_serper_api_key()
        ),
        "Content-Type": (
            "application/json"
        ),
    }

    payload = {
        "q": query,
        "hl": "en",
        "num": MAX_SEARCH_RESULTS,
    }

    try:
        response = requests.post(
            SERPER_SEARCH_URL,
            headers=headers,
            json=payload,
            timeout=REQUEST_TIMEOUT_SECONDS,
        )

        response.raise_for_status()

        data = response.json()

    except requests.HTTPError as exc:
        response = exc.response

        status_code = (
            response.status_code
            if response is not None
            else "unknown"
        )

        response_text = (
            response.text[:1000]
            if response is not None
            else "No response body."
        )

        raise RuntimeError(
            "Workshop Serper search failed. "
            f"Status: {status_code}. "
            f"Response: {response_text}"
        ) from None

    except requests.RequestException as exc:
        raise RuntimeError(
            "Workshop Serper request failed: "
            f"{exc.__class__.__name__}: {exc}"
        ) from None

    organic_results = data.get(
        "organic",
        [],
    )

    if not isinstance(
        organic_results,
        list,
    ):
        return []

    current_date = (
        get_current_datetime().date()
    )

    candidates = []

    seen_links: set[str] = set()

    for result in organic_results:
        if not isinstance(
            result,
            dict,
        ):
            continue

        candidate = (
            normalize_serper_candidate(
                result=result,
                current_date=current_date,
                student_location=(
                    student_location
                ),
            )
        )

        if candidate is None:
            continue

        link = candidate[
            "link"
        ]

        if link in seen_links:
            continue

        seen_links.add(
            link
        )

        candidates.append(
            candidate
        )

        if (
            len(candidates)
            >= MAX_SEARCH_RESULTS
        ):
            break

    return candidates


# =====================================================================
# JSON EXTRACTION
# =====================================================================

def extract_json_object(
    content: Any,
) -> dict[str, Any]:
    """
    Extract a JSON object from an LLM response.

    Handles:
    - normal strings
    - dictionary responses
    - list-based content

    The Workshop Agent requires JSON-only output, but this helper gives
    additional protection if the model surrounds the JSON with text.
    """

    if isinstance(
        content,
        dict,
    ):
        return content

    if isinstance(
        content,
        list,
    ):
        text_parts = []

        for item in content:
            if isinstance(
                item,
                str,
            ):
                text_parts.append(
                    item
                )

            elif isinstance(
                item,
                dict,
            ):
                if "text" in item:
                    text_parts.append(
                        str(
                            item["text"]
                        )
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

    try:
        return json.loads(
            text
        )

    except json.JSONDecodeError:
        pass

    match = re.search(
        r"\{.*\}",
        text,
        re.DOTALL,
    )

    if not match:
        raise ValueError(
            "No valid JSON object found "
            "in Workshop Agent response."
        )

    return json.loads(
        match.group(0)
    )


# =====================================================================
# OPTIONAL FIELD VALIDATION
# =====================================================================

def validate_optional_evidence_text(
    value: Any,
    evidence: str,
) -> str | None:
    """
    Validate optional text generated by the LLM.

    Used for:
    - organizer
    - speaker

    If the name cannot be found in the original Serper evidence,
    it is removed instead of allowing a hallucinated value.
    """

    if not isinstance(
        value,
        str,
    ):
        return None

    clean_value = (
        value.strip()
    )

    if not clean_value:
        return None

    if not text_is_supported_by_evidence(
        clean_value,
        evidence,
    ):
        return None

    return clean_value


# =====================================================================
# FINAL LLM OUTPUT VALIDATION
# =====================================================================

def validate_workshop_output(
    parsed_output: dict[str, Any],
    candidates: list[
        dict[str, Any]
    ],
) -> dict[str, Any]:
    """
    Validate the workshops selected by the LLM.

    The LLM decides relevance.

    Python decides factual integrity.

    Important protections:

    - selected link must exist in Serper results
    - title must be supported by Serper evidence
    - location must be supported by Serper evidence
    - date comes directly from normalized search evidence
    - time comes directly from normalized search evidence
    - cost comes directly from normalized search evidence
    - organizer/speaker are removed if unsupported
    - duplicate events are removed
    """

    if set(
        parsed_output.keys()
    ) != {
        "workshops"
    }:
        raise ValueError(
            "Workshop Agent output must "
            "contain exactly 'workshops'."
        )

    raw_workshops = (
        parsed_output.get(
            "workshops"
        )
    )

    if not isinstance(
        raw_workshops,
        list,
    ):
        raise ValueError(
            "workshops must be a list."
        )

    candidates_by_link = {
        candidate["link"]: candidate
        for candidate in candidates
        if isinstance(
            candidate.get("link"),
            str,
        )
    }

    validated_workshops = []

    seen_links: set[str] = set()

    for workshop in raw_workshops:
        if not isinstance(
            workshop,
            dict,
        ):
            continue

        link = workshop.get(
            "link"
        )

        if not isinstance(
            link,
            str,
        ):
            continue

        candidate = (
            candidates_by_link.get(
                link
            )
        )

        # The LLM selected a URL that did not
        # come from Serper.
        if candidate is None:
            continue

        if link in seen_links:
            continue

        evidence = candidate[
            "evidence"
        ]

        # -----------------------------------------------------------
        # TITLE
        # -----------------------------------------------------------

        title = workshop.get(
            "title"
        )

        if not isinstance(
            title,
            str,
        ):
            continue

        title = title.strip()

        if (
            not title
            or not text_is_supported_by_evidence(
                title,
                evidence,
            )
        ):
            continue

        # -----------------------------------------------------------
        # LOCATION
        # -----------------------------------------------------------

        location = workshop.get(
            "location"
        )

        if not isinstance(
            location,
            str,
        ):
            continue

        location = (
            location.strip()
        )

        if not location:
            continue

        if (
            location.lower()
            == "online"
        ):
            if not is_online_event(
                evidence
            ):
                continue

            location = "Online"

        elif not text_is_supported_by_evidence(
            location,
            evidence,
        ):
            # The agent may return the original
            # student location when that location
            # was already verified during search.
            location_hint = (
                candidate.get(
                    "location_hint"
                )
            )

            if (
                not isinstance(
                    location_hint,
                    str,
                )
                or location.lower()
                != location_hint.lower()
            ):
                continue

        # -----------------------------------------------------------
        # OPTIONAL ORGANIZER / SPEAKER
        # -----------------------------------------------------------

        organizer = (
            validate_optional_evidence_text(
                workshop.get(
                    "organizer"
                ),
                evidence,
            )
        )

        speaker = (
            validate_optional_evidence_text(
                workshop.get(
                    "speaker"
                ),
                evidence,
            )
        )

        # -----------------------------------------------------------
        # FINAL FACTUAL RESULT
        # -----------------------------------------------------------

        validated_workshops.append({
            "title": title,

            "organizer": organizer,

            "speaker": speaker,

            # Do not trust the LLM to copy
            # factual dates/times/prices.
            "date": candidate[
                "event_date"
            ],

            "time": candidate.get(
                "event_time"
            ),

            "location": location,

            "cost_type": candidate[
                "cost_type"
            ],

            "price": candidate.get(
                "price"
            ),

            "link": link,
        })

        seen_links.add(
            link
        )

        if (
            len(validated_workshops)
            >= MAX_SELECTED_WORKSHOPS
        ):
            break

    return {
        "workshops": (
            validated_workshops
        )
    }


# =====================================================================
# WORKSHOP AGENT PROMPT
# =====================================================================

WORKSHOP_AGENT_SYSTEM_PROMPT = """
You are a Workshop Recommendation Agent for a Computer Science
learning platform.

Your responsibility is ONLY to evaluate real workshop/event search
candidates supplied by the application and select the strongest ones
for a student.


==================================================
INPUT
==================================================

You receive:

- the student's broad roadmap topic
- the student's optional location
- the actual current date
- optional manager regeneration feedback
- verified search candidates returned by Serper


The roadmap topic is intentionally broad.

Examples:

A Web Development student may also benefit from:
- frontend topics
- backend topics
- APIs
- databases
- deployment
- web security

A Cyber Security student may also benefit from:
- ethical hacking
- penetration testing
- application security
- security research
- vulnerability-related technical events

A Machine Learning student may also benefit from:
- artificial intelligence
- deep learning
- MLOps
- NLP
- computer vision
- model deployment

These examples explain semantic relevance.

Do not require the workshop title to exactly contain the roadmap topic.


==================================================
SEARCH EVIDENCE
==================================================

The application already performed the web search.

You do NOT perform another search.

Every candidate contains search evidence collected from Serper.

You may select ONLY events from the supplied candidates.

Never invent:
- workshops
- events
- URLs
- dates
- times
- locations
- organizers
- speakers
- prices


==================================================
SELECTION QUALITY
==================================================

Evaluate every candidate carefully.

Prefer workshops/events that provide useful technical learning for
a Computer Science student.

Good candidates may include:
- workshops
- hands-on technical events
- technical training sessions
- developer meetups
- webinars
- technical bootcamps
- conference workshops
- developer community events
- practical technical sessions


Reject candidates that are mainly:
- unrelated networking
- business promotion
- job advertisements
- ordinary university degree programs
- prerecorded courses
- generic article pages
- generic event directories
- broad conference listing pages
- non-technical events


A generic directory page should not be selected merely because it
mentions many conferences.

Prefer a concrete individual event whose link allows the student to
learn more about that specific event.


==================================================
LOCAL AND ONLINE EVENTS
==================================================

When student_location is provided:

You may select:
- relevant physical events near the student
- relevant online events

Try to provide a useful mix when strong candidates of both types exist.

Interpret the requested geographic scope carefully:
- If the student supplied a country or broad region, physical events in
  cities or localities inside that country or region are eligible.
- For example, when the requested location is Lebanon, an event whose
  evidence says Beirut may be selected because Beirut is in Lebanon.
- If the student supplied a specific city, prefer events in that city.
  A nearby event may be selected only when the geographic relationship is
  reasonable and useful to the student.

You may use geographic knowledge only to decide whether the location in
the candidate evidence is inside or near the student's requested area.
Never use geographic knowledge to invent, replace, or rewrite an event's
actual location.

Do not select a physical event from a completely unrelated geographic
area. Do not select a physical event unless its candidate evidence names
an actual city, country, region, or venue.

When student_location is not provided:

Select only online events.


==================================================
DATE RULES
==================================================

Candidates have already been checked by Python for current/future dates.

Do not change the supplied event date.

Never convert a past event into a future event.

Never guess a date.


==================================================
LOCATION RULES
==================================================

The final field is:

"location"

There is NO separate mode field.

For online workshops use exactly:

"Online"

For physical events use the actual location supported by the search
evidence.

Return the evidence-supported event location, not the student's broader
location. For example, if the student requested Lebanon and the evidence
says Beirut, return Beirut; do not replace it with Lebanon.

Do not invent or modify a city, country, region, or venue.


==================================================
ORGANIZER AND SPEAKER
==================================================

organizer and speaker are optional.

They may be null.

Only include a value when the search evidence explicitly supports it.

Do not guess either field.

An organization such as a developer community, academy, university,
or company is usually an organizer, not automatically a speaker.


==================================================
COST
==================================================

cost_type is already determined from search evidence.

Possible values are:

- free
- paid
- not_specified

Do not assume that an event is free when the price was not provided.


==================================================
MANAGER REGENERATION
==================================================

When regeneration feedback is supplied, use it when selecting among
the new search candidates.

Examples:

- prefer online workshops
- prefer more practical workshops
- prefer events closer to the student's location

Do not violate evidence rules merely to satisfy manager feedback.


==================================================
FINAL SELECTION
==================================================

Select at most 6 workshops.

Return fewer than 6 when only a small number are strong.

It is better to return:
- 2 strong workshops

than:
- 6 weak or unrelated workshops


==================================================
OUTPUT
==================================================

Return ONLY valid JSON.

Do not wrap JSON in Markdown.

Do not add comments.

Do not add explanations before or after the JSON.

Do not add additional fields.

Return exactly:

{
  "workshops": [
    {
      "title": "Exact event title supported by the search evidence",
      "organizer": "Organizer name or null",
      "speaker": "Speaker name or null",
      "date": "YYYY-MM-DD",
      "time": "Event time or null",
      "location": "Actual physical location or Online",
      "cost_type": "free, paid, or not_specified",
      "price": "Price or null",
      "link": "Exact candidate URL"
    }
  ]
}

If no candidate is strong enough, return:

{
  "workshops": []
}
"""


# =====================================================================
# PUBLIC RUNNER
# =====================================================================

def run_workshop_agent(
    roadmap_topic: str,
    student_location: str | None = None,
    regeneration_feedback: str | None = None,
) -> dict[str, Any]:
    """
    Run the complete standalone Workshop Agent.

    This function is NOT a LangGraph node.

    Workflow responsibilities will be added later.

    Flow:

        roadmap topic/location
                ↓
        ONE Serper request
                ↓
        normalize candidates
                ↓
        LLM chooses relevant workshops
                ↓
        Python validates selected workshops
                ↓
        return result

    If the LLM produces invalid JSON or violates the output contract,
    one automatic validation retry is allowed.

    IMPORTANT:
    The retry reuses the SAME Serper candidates.

    Therefore:
        one run = one Serper request

    even when the LLM needs a validation retry.
    """

    topic = (
        roadmap_topic.strip()
    )

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    location = None

    if (
        student_location
        and student_location.strip()
    ):
        location = (
            student_location.strip()
        )

    try:
        # -----------------------------------------------------------
        # ONE SERPER REQUEST
        # -----------------------------------------------------------

        candidates = (
            search_workshop_candidates(
                roadmap_topic=topic,
                student_location=location,
                regeneration_feedback=(
                    regeneration_feedback
                ),
            )
        )

        if not candidates:
            return {
                "workshops": []
            }

        current_datetime = (
            get_current_datetime()
        )

        user_message = f"""
CURRENT DATE/TIME

{current_datetime.isoformat()}


ROADMAP TOPIC

{topic}


STUDENT LOCATION

{location if location else "Not provided"}


MANAGER REGENERATION FEEDBACK

{regeneration_feedback if regeneration_feedback else "None"}


SERPER WORKSHOP CANDIDATES

{json.dumps(
    candidates,
    ensure_ascii=False,
)}


TASK

Evaluate the supplied candidates and select only the strongest real
upcoming workshops/events that are useful to a student studying the
roadmap topic.

Use only the supplied evidence.

Return at most {MAX_SELECTED_WORKSHOPS} workshops.

Return only valid JSON.
"""

        maximum_attempts = 2

        last_error: Exception | None = (
            None
        )

        for attempt in range(
            1,
            maximum_attempts + 1,
        ):
            messages = [
                {
                    "role": "system",
                    "content": (
                        WORKSHOP_AGENT_SYSTEM_PROMPT
                    ),
                },
                {
                    "role": "user",
                    "content": (
                        user_message
                    ),
                },
            ]

            # -------------------------------------------------------
            # VALIDATION RETRY
            # -------------------------------------------------------

            if last_error is not None:
                messages.append(
                    {
                        "role": "user",
                        "content": f"""
Your previous Workshop Agent response failed validation.

VALIDATION ERROR

{last_error}

Generate the COMPLETE JSON response again.

Use only the same supplied Serper candidates.

Do not invent events or factual information.

Every workshop URL must exactly match one supplied candidate.

Return only valid JSON.
""",
                    }
                )

            try:
                # ---------------------------------------------------
                # LLM SELECTION
                # ---------------------------------------------------

                result = llm.invoke(
                    messages
                )

                # ---------------------------------------------------
                # JSON EXTRACTION
                # ---------------------------------------------------

                parsed_output = (
                    extract_json_object(
                        result.content
                    )
                )

                # ---------------------------------------------------
                # FACTUAL VALIDATION
                # ---------------------------------------------------

                validated_output = (
                    validate_workshop_output(
                        parsed_output=(
                            parsed_output
                        ),
                        candidates=(
                            candidates
                        ),
                    )
                )

                return validated_output

            except Exception as error:
                last_error = error

        raise RuntimeError(
            "Workshop Agent failed "
            "after validation retry: "
            f"{last_error}"
        ) from last_error

    except Exception as error:
        return {
            "workshops": [],
            "error": (
                "Workshop Agent failed: "
                f"{str(error)}"
            ),
        }


# =====================================================================
# LOCAL TEST
# =====================================================================

if __name__ == "__main__":
    result = run_workshop_agent(
        roadmap_topic=(
            "Cyber Security"
        ),
        student_location=(
            "Beirut, Lebanon"
        ),
    )

    print(
        json.dumps(
            result,
            indent=2,
            ensure_ascii=False,
        )
    )
