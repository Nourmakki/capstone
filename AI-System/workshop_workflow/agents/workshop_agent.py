import os
import json
import re
import requests
from typing import Any
from datetime import date, datetime, timedelta
from dotenv import load_dotenv
from langchain.agents import create_agent
from langchain.tools import tool
from agents.llm import llm


load_dotenv()

def get_current_datetime() -> datetime:
    """
    Return the actual current local datetime.

    Workshop searches must depend on the real current date
    so past workshops are not recommended.
    """

    return datetime.now().astimezone()


def safe_json(
    data: dict[str, Any],
) -> str:
    return json.dumps(
        data,
        ensure_ascii=False,
    )


def build_search_suggestions(
    roadmap_topic: str,
    student_location: str | None,
) -> list[str]:

    topic = roadmap_topic.strip()

    suggestions = [
        f"upcoming online {topic} workshop",
        f"upcoming online {topic} developer event",
    ]

    if student_location:
        location = student_location.strip()

        suggestions = [
            f"upcoming {topic} workshop {location}",
            f"upcoming {topic} developer event {location}",
            *suggestions,
        ]

    return suggestions


# ---------------------------------------------------------------------------
# Date helpers
# ---------------------------------------------------------------------------

MONTH_PATTERN = (
    r"(Jan(?:uary)?|Feb(?:ruary)?|Mar(?:ch)?|"
    r"Apr(?:il)?|May|Jun(?:e)?|Jul(?:y)?|"
    r"Aug(?:ust)?|Sep(?:tember)?|Oct(?:ober)?|"
    r"Nov(?:ember)?|Dec(?:ember)?)"
)


def normalize_event_date(
    raw_date: Any,
    current_date: date,
) -> str | None:
    """
    Convert a SerpAPI event date into YYYY-MM-DD.

    Important safety rule:
    - if the date cannot be determined, discard it
    - if the result is already in the past, discard it
    - never guess that an old month means next year
    """

    if isinstance(raw_date, dict):
        raw_date = (
            raw_date.get("start_date")
            or raw_date.get("when")
            or ""
        )

    if not isinstance(raw_date, str):
        return None

    text = raw_date.strip()

    if not text:
        return None

    lower_text = text.lower()

    if "today" in lower_text:
        return current_date.isoformat()

    if "tomorrow" in lower_text:
        return (
            current_date
            + timedelta(days=1)
        ).isoformat()

    # ---------------------------------------------------------------
    # ISO date
    # ---------------------------------------------------------------

    iso_match = re.search(
        r"\b(\d{4})-(\d{2})-(\d{2})\b",
        text,
    )

    if iso_match:
        try:
            parsed = date(
                int(iso_match.group(1)),
                int(iso_match.group(2)),
                int(iso_match.group(3)),
            )

            if parsed < current_date:
                return None

            return parsed.isoformat()

        except ValueError:
            return None

    # ---------------------------------------------------------------
    # Month-name dates such as:
    # Sep 20
    # September 20
    # Sep 20, 2026
    # ---------------------------------------------------------------

    match = re.search(
        rf"{MONTH_PATTERN}\s+"
        r"(\d{1,2})"
        r"(?:,\s*(\d{4}))?",
        text,
        re.IGNORECASE,
    )

    if not match:
        return None

    month_text = match.group(1)
    day_text = match.group(2)
    year_text = match.group(3)

    month_number = None

    for fmt in (
        "%b",
        "%B",
    ):
        try:
            month_number = (
                datetime.strptime(
                    month_text,
                    fmt,
                ).month
            )
            break

        except ValueError:
            continue

    if month_number is None:
        return None

    year = (
        int(year_text)
        if year_text
        else current_date.year
    )

    try:
        parsed = date(
            year,
            month_number,
            int(day_text),
        )

    except ValueError:
        return None

    # Very important:
    #
    # If Google returns "Mar 15" in September without a year,
    # do NOT silently convert it to March of next year.
    #
    # It is safer to discard the result.
    if parsed < current_date:
        return None

    return parsed.isoformat()


# ---------------------------------------------------------------------------
# Event normalization
# ---------------------------------------------------------------------------

def extract_location(
    event: dict[str, Any],
) -> str | None:

    searchable_parts: list[str] = []

    for field in (
        "title",
        "type",
        "venue",
        "description",
    ):
        value = event.get(field)

        if isinstance(value, str):
            searchable_parts.append(value)

    address = event.get("address")

    if isinstance(address, list):
        searchable_parts.extend(
            str(value)
            for value in address
            if value
        )

    elif isinstance(address, str):
        searchable_parts.append(address)

    combined_text = " ".join(
        searchable_parts
    ).lower()

    if (
        "online" in combined_text
        or "virtual" in combined_text
    ):
        return "Online"

    location_parts: list[str] = []

    venue = event.get("venue")

    if (
        isinstance(venue, str)
        and venue.strip()
    ):
        location_parts.append(
            venue.strip()
        )

    if isinstance(address, list):
        for item in address:
            value = str(item).strip()

            if (
                value
                and value not in location_parts
            ):
                location_parts.append(
                    value
                )

    elif (
        isinstance(address, str)
        and address.strip()
        and address.strip()
        not in location_parts
    ):
        location_parts.append(
            address.strip()
        )

    if not location_parts:
        return None

    return ", ".join(
        location_parts
    )


def extract_price(
    event: dict[str, Any],
) -> tuple[
    str,
    str | None,
]:

    price = event.get("price")
    extracted_price = event.get(
        "extracted_price"
    )

    if not price:
        ticket_info = event.get(
            "ticket_info",
            [],
        )

        if isinstance(ticket_info, list):
            for ticket in ticket_info:
                if not isinstance(
                    ticket,
                    dict,
                ):
                    continue

                ticket_price = ticket.get(
                    "price"
                )

                if ticket_price:
                    price = ticket_price
                    break

    if isinstance(price, str):
        clean_price = price.strip()

        if "free" in clean_price.lower():
            return (
                "free",
                clean_price,
            )

        if clean_price:
            return (
                "paid",
                clean_price,
            )

    if isinstance(
        extracted_price,
        (int, float),
    ):
        if extracted_price == 0:
            return (
                "free",
                "Free",
            )

        return (
            "paid",
            str(extracted_price),
        )

    return (
        "not_specified",
        None,
    )


def normalize_event(
    event: dict[str, Any],
    current_date: date,
) -> dict[str, Any] | None:

    title = str(
        event.get(
            "title",
            "",
        )
    ).strip()

    link = str(
        event.get(
            "link",
            "",
        )
    ).strip()

    if (
        not title
        or not link.startswith("http")
    ):
        return None

    normalized_date = (
        normalize_event_date(
            event.get("date"),
            current_date,
        )
    )

    if normalized_date is None:
        return None

    location = extract_location(
        event
    )

    # We need either:
    # - a real location
    # - "Online"
    #
    # Do not invent an unknown location.
    if location is None:
        return None

    cost_type, price = extract_price(
        event
    )

    event_time = event.get("time")

    if not isinstance(
        event_time,
        str,
    ):
        event_time = None

    elif not event_time.strip():
        event_time = None

    else:
        event_time = event_time.strip()

    description = event.get(
        "description"
    )

    if not isinstance(
        description,
        str,
    ):
        description = ""

    return {
        "title": title,
        "date": normalized_date,
        "time": event_time,
        "location": location,
        "cost_type": cost_type,
        "price": price,
        "link": link,

        # The description is only supplied to the agent
        # as evidence for organizer/speaker extraction.
        # It is not part of the final Workshop output.
        "description": description,
    }


# ---------------------------------------------------------------------------
# SerpAPI tool
# ---------------------------------------------------------------------------

@tool
def serpapi_workshop_search(
    query: str,
    location: str | None = None,
) -> str:
    """
    Search Google through SerpAPI for real upcoming workshops and
    technology events.

    Use location only for local/in-person searches.
    For online searches, leave location empty.

    The tool automatically removes:
    - past events
    - events without a usable date
    - events without a usable location
    - results without a real link
    """

    try:
        api_key = os.getenv(
            "SERPAPI_API_KEY"
        )

        if not api_key:
            return safe_json({
                "tool": (
                    "serpapi_workshop_search"
                ),
                "query": query,
                "results": [],
                "error": (
                    "Missing SERPAPI_API_KEY "
                    "in .env"
                ),
            })

        params: dict[str, Any] = {
            "engine": "google",
            "q": query,
            "api_key": api_key,
            "hl": "en",
        }

        if (
            location
            and location.strip()
        ):
            params["location"] = (
                location.strip()
            )

        response = requests.get(
            "https://serpapi.com/search.json",
            params=params,
            timeout=20,
        )

        response.raise_for_status()

        data = response.json()

        if data.get("error"):
            return safe_json({
                "tool": (
                    "serpapi_workshop_search"
                ),
                "query": query,
                "results": [],
                "error": str(
                    data["error"]
                ),
            })

        raw_events = data.get(
            "events_results",
            [],
        )

        if not isinstance(
            raw_events,
            list,
        ):
            raw_events = []

        current_date = (
            get_current_datetime().date()
        )

        normalized_events = []

        for event in raw_events:
            if not isinstance(
                event,
                dict,
            ):
                continue

            normalized = normalize_event(
                event,
                current_date,
            )

            if normalized is not None:
                normalized_events.append(
                    normalized
                )

        return safe_json({
            "tool": (
                "serpapi_workshop_search"
            ),
            "query": query,
            "results": normalized_events[
                :10
            ],
            "error": None,
        })

    except requests.exceptions.RequestException as e:
        return safe_json({
            "tool": (
                "serpapi_workshop_search"
            ),
            "query": query,
            "results": [],
            "error": (
                "SerpAPI workshop search "
                f"failed: {str(e)}"
            ),
        })

    except Exception as e:
        return safe_json({
            "tool": (
                "serpapi_workshop_search"
            ),
            "query": query,
            "results": [],
            "error": (
                "Unexpected SerpAPI workshop "
                f"search error: {str(e)}"
            ),
        })


# ---------------------------------------------------------------------------
# System prompt
# ---------------------------------------------------------------------------

WORKSHOP_SYSTEM_PROMPT = """
You are a Workshop Recommendation Agent for a Computer Science learning platform.

Your responsibility is ONLY to find real upcoming technical workshops and events
that are useful for a student studying the supplied roadmap topic.

You MUST use serpapi_workshop_search before answering.
Never answer from your knowledge
Never invent an event.
Never invent a URL.
Never return an event from model memory.

The roadmap topic is intentionally broad.

Examples:
- Web Development may include frontend, backend, APIs, web security,
  deployment, JavaScript frameworks, databases, and related web technologies.
- Mobile Development may include Flutter, React Native, Android, iOS,
  mobile UI, APIs, testing, deployment, and related mobile technologies.
- Machine Learning may include ML, deep learning, data science,
  MLOps, computer vision, NLP, model deployment, and related topics.

Do not require an event title to exactly match the roadmap title.
A workshop may be recommended if it is clearly useful to a student studying
that roadmap.

SEARCH RULES

If student_location is provided:
- search for relevant local workshops/events
- ALSO search for relevant online workshops/events

If student_location is not provided:
- search ONLY for online workshops/events

For online searches:
- do not pass a geographic location to the search tool

For local searches:
- use the student's location

Search for upcoming workshops, technical events, training sessions,
developer events, bootcamps, meetups, conference workshops,
and practical technical sessions.

Do not return:
- past events
- unrelated business events
- generic networking events with no useful technical learning
- jobs
- normal university degree programs
- prerecorded courses
- articles
- tutorials
- events with no real event link

DATE RULES

The search tool already removes events whose date is in the past.
Still verify that every selected event is current or future.

Never change an event date returned by the tool.
Never guess a missing date.

LOCATION RULES

The final location field must contain only one of:

1. The actual physical event location returned by the tool.

or

2. "Online"

Do not return a separate mode field.

ORGANIZER AND SPEAKER RULES

organizer and speaker may be null.

Only return an organizer or speaker when that information is explicitly
supported by the event title or description returned by the search tool.

Never guess the organizer.
Never guess the speaker.

COST RULES

cost_type must be exactly one of:
- "free"
- "paid"
- "not_specified"

Never assume an event is free because a price was not returned.

REGENERATION

If regeneration feedback is provided:
- use it to improve the new search
- perform fresh tool searches
- do not simply repeat the previous answer
- still follow all workshop, date, location, and source rules

RESULT SELECTION

Return the strongest relevant upcoming workshops.

Prefer:
1. strong relevance to the roadmap topic
2. useful technical learning
3. reliable event information
4. nearer upcoming dates

Return at most 6 workshops.

Return fewer if there are not enough strong real events.

SOURCE RULES

Every link must appear exactly in a serpapi_workshop_search result.
Never construct or modify URLs.

OUTPUT FORMAT

Return ONLY valid JSON.

Do not wrap JSON in Markdown.
Do not add comments.
Do not add explanations before or after JSON.
Do not add additional fields.

Return exactly:

{
  "workshops": [
    {
      "title": "Workshop title",
      "organizer": "Organizer name or null",
      "speaker": "Speaker name or null",
      "date": "YYYY-MM-DD",
      "time": "Event time or null",
      "location": "Actual physical location or Online",
      "cost_type": "free, paid, or not_specified",
      "price": "Exact price text or null",
      "link": "Exact event URL from the search tool"
    }
  ]
}

If no relevant upcoming workshops can be verified, return:

{
  "workshops": []
}
"""


# ---------------------------------------------------------------------------
# Agent
# ---------------------------------------------------------------------------

workshop_agent = create_agent(
    model=llm,
    system_prompt=WORKSHOP_SYSTEM_PROMPT,
    tools=[
        serpapi_workshop_search,
    ],
)


# ---------------------------------------------------------------------------
# JSON extraction
# ---------------------------------------------------------------------------

def extract_json_object(
    content: Any,
) -> dict[str, Any]:

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
        text = str(content)

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
            "in Workshop Agent response: "
            f"{text}"
        )

    return json.loads(
        match.group(0)
    )


# ---------------------------------------------------------------------------
# Tool evidence collection
# ---------------------------------------------------------------------------

def collect_tool_workshops(
    agent_result: dict[str, Any],
) -> dict[str, dict[str, Any]]:
    """
    Store every real event returned by SerpAPI,
    indexed by its exact link.

    This prevents the model from returning
    invented event URLs.
    """

    workshops_by_link: dict[
        str,
        dict[str, Any],
    ] = {}

    messages = agent_result.get(
        "messages",
        [],
    )

    for message in messages:
        if getattr(
            message,
            "type",
            "",
        ) != "tool":
            continue

        content = getattr(
            message,
            "content",
            "",
        )

        try:
            parsed = json.loads(
                content
            )

        except Exception:
            continue

        if (
            parsed.get("tool")
            != "serpapi_workshop_search"
        ):
            continue

        results = parsed.get(
            "results",
            [],
        )

        if not isinstance(
            results,
            list,
        ):
            continue

        for event in results:
            if not isinstance(
                event,
                dict,
            ):
                continue

            link = event.get(
                "link"
            )

            if (
                isinstance(link, str)
                and link.startswith("http")
            ):
                workshops_by_link[
                    link
                ] = event

    return workshops_by_link


def verify_optional_name(
    value: Any,
    source_event: dict[str, Any],
) -> str | None:
    """
    Organizer/speaker must appear in actual tool evidence.

    If the model invents a name, remove it.
    """

    if not isinstance(
        value,
        str,
    ):
        return None

    clean_value = value.strip()

    if not clean_value:
        return None

    evidence = " ".join([
        str(
            source_event.get(
                "title",
                "",
            )
        ),
        str(
            source_event.get(
                "description",
                "",
            )
        ),
    ]).lower()

    if clean_value.lower() not in evidence:
        return None

    return clean_value


# ---------------------------------------------------------------------------
# Output validation
# ---------------------------------------------------------------------------

def validate_workshop_output(
    parsed_output: dict[str, Any],
    allowed_workshops: dict[
        str,
        dict[str, Any],
    ],
) -> dict[str, Any]:

    if set(
        parsed_output.keys()
    ) != {
        "workshops"
    }:
        raise ValueError(
            "Workshop Agent output must "
            "contain exactly 'workshops'."
        )

    raw_workshops = parsed_output.get(
        "workshops"
    )

    if not isinstance(
        raw_workshops,
        list,
    ):
        raise ValueError(
            "workshops must be a list."
        )

    validated = []
    seen_links: set[str] = set()

    for item in raw_workshops:
        if not isinstance(
            item,
            dict,
        ):
            continue

        link = item.get(
            "link"
        )

        if not isinstance(
            link,
            str,
        ):
            continue

        source_event = (
            allowed_workshops.get(
                link
            )
        )

        # The event was not returned by SerpAPI.
        if source_event is None:
            continue

        if link in seen_links:
            continue

        seen_links.add(
            link
        )

        validated.append({
            # Use factual fields directly from
            # the search tool rather than trusting
            # the model to copy them correctly.
            "title": source_event[
                "title"
            ],

            "organizer": (
                verify_optional_name(
                    item.get(
                        "organizer"
                    ),
                    source_event,
                )
            ),

            "speaker": (
                verify_optional_name(
                    item.get(
                        "speaker"
                    ),
                    source_event,
                )
            ),

            "date": source_event[
                "date"
            ],

            "time": source_event.get(
                "time"
            ),

            "location": source_event[
                "location"
            ],

            "cost_type": source_event[
                "cost_type"
            ],

            "price": source_event.get(
                "price"
            ),

            "link": link,
        })

        if len(validated) >= 6:
            break

    return {
        "workshops": validated
    }


# ---------------------------------------------------------------------------
# Public runner
# ---------------------------------------------------------------------------

def run_workshop_agent(
    roadmap_topic: str,
    student_location: str | None = None,
    regeneration_feedback: str | None = None,
) -> dict[str, Any]:
    """
    Run the Workshop Recommendation Agent.

    This is not a LangGraph node.
    The workflow will call this function.
    """

    current_datetime = (
        get_current_datetime()
    )

    search_suggestions = (
        build_search_suggestions(
            roadmap_topic,
            student_location,
        )
    )

    try:
        user_message = f"""
Current date/time:
{current_datetime.isoformat()}

Roadmap topic:
{roadmap_topic}

Student location:
{student_location if student_location else "Not provided"}

Regeneration feedback:
{regeneration_feedback if regeneration_feedback else "None"}

Suggested search queries:
{json.dumps(search_suggestions, ensure_ascii=False)}

Task:
Find real, upcoming workshops and technical events
relevant to students studying this roadmap topic.

Important:
- Treat the roadmap topic as a broad technical learning area.
- Do not require workshops to match the exact roadmap title.
- If a student location exists, search both local and online events.
- If no student location exists, search only online events.
- Use SerpAPI search results only.
- Never return a past event.
- Never invent a workshop.
- Never invent or modify a workshop link.
"""

        agent_result = (
            workshop_agent.invoke({
                "messages": [
                    {
                        "role": "user",
                        "content": user_message,
                    }
                ]
            })
        )

        raw_output = (
            agent_result[
                "messages"
            ][-1].content
        )

        parsed_output = (
            extract_json_object(
                raw_output
            )
        )

        allowed_workshops = (
            collect_tool_workshops(
                agent_result
            )
        )

        validated_output = (
            validate_workshop_output(
                parsed_output,
                allowed_workshops,
            )
        )

        return validated_output

    except Exception as e:
        return {
            "workshops": [],
            "error": (
                "Workshop Agent failed: "
                f"{str(e)}"
            ),
        }


# ---------------------------------------------------------------------------
# Local test
# ---------------------------------------------------------------------------

if __name__ == "__main__":
    print(
        json.dumps(
            run_workshop_agent(
                roadmap_topic=(
                    "AI engineering"
                ),
                student_location=(
                    "Beirut, Lebanon"
                ),
            ),
            indent=2,
            ensure_ascii=False,
        )
    )
