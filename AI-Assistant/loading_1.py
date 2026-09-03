"""STEP 1: Load the platform's catalogue.

The reference project read documents from a folder. Ours reads the platform
itself: GET /api/assistant/corpus returns the roadmaps, modules, material and
platform rules as prose passages, already filtered to published and approved
content with nothing personal in it.

The filtering is done there rather than here on purpose. The index is built
once and answers everybody, so anything that reaches this file is effectively
public - and the rules about what may be published belong with the platform
that enforces them everywhere else, not with a script.
"""

import os

import httpx
from dotenv import load_dotenv

load_dotenv()


def load_corpus() -> list[dict]:
    """Fetch every passage the platform is willing to have indexed."""
    print("\n" + "=" * 25)
    print("STEP 1: Loading the platform catalogue")
    print("=" * 25)

    base = os.getenv("LARAVEL_API_URL", "http://127.0.0.1:8000/api").rstrip("/")
    token = os.getenv("LARAVEL_API_TOKEN", "").strip()

    if not token:
        raise ValueError(
            "LARAVEL_API_TOKEN is missing from .env. The corpus endpoint is "
            "behind authentication - see the README for how to mint one."
        )

    response = httpx.get(
        f"{base}/assistant/corpus",
        headers={
            "Accept": "application/json",
            "Authorization": f"Bearer {token}",
        },
        timeout=60,
    )

    if response.status_code == 401:
        raise ValueError(
            "The platform rejected LARAVEL_API_TOKEN. It has probably been "
            "revoked - mint a new one and put it in .env."
        )

    response.raise_for_status()
    body = response.json()

    passages = body.get("passages", [])

    print(f"Loaded {len(passages)} passages from {base}")

    counts: dict[str, int] = {}
    for passage in passages:
        counts[passage["type"]] = counts.get(passage["type"], 0) + 1

    for kind, count in sorted(counts.items()):
        print(f"  - {count} {kind}")

    if not passages:
        raise ValueError(
            "The platform returned no passages. Nothing is published and "
            "approved yet, so there is nothing to index."
        )

    return passages
