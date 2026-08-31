import os
import re
from time import monotonic
from datetime import datetime, timezone
from html import unescape
from html.parser import HTMLParser
from typing import Any, Literal
from urllib.parse import urlparse

import requests
from dotenv import load_dotenv


load_dotenv()


# =====================================================================
# CONFIGURATION
# =====================================================================

SERPER_SEARCH_URL = "https://google.serper.dev/search"

RESULTS_PER_QUERY = 5

MAX_CORE_EVIDENCE = 3
MAX_FRESHNESS_EVIDENCE = 2
CANDIDATE_LIMIT = 6

PAGE_TIMEOUT_SECONDS = 8
MAX_EXCERPT_CHARACTERS = 1000
MAX_SOURCES_PER_HOST = 1

MAX_PAGE_BYTES = 250_000
MAX_PAGE_FETCH_SECONDS = 6

RESEARCH_VERSION = "roadmap_research_v3"


ResearchCategory = Literal[
    "domain",
    "market",
]

EvidenceRole = Literal[
    "core",
    "freshness",
    "market",
]

SourceType = Literal[
    "official_docs",
    "standards",
    "professional_org",
    "academic",
    "curriculum",
    "survey_report",
    "conference",
    "engineering",
    "news",
    "job_market",
    "general",
    "discovery_only",
]


# =====================================================================
# SOURCE FAMILIES
# =====================================================================

AUTHORITATIVE_TECHNICAL_HOSTS = {
    "developer.mozilla.org",
    "web.dev",
    "python.org",
    "nodejs.org",
    "typescriptlang.org",
    "react.dev",
    "vuejs.org",
    "angular.dev",
    "go.dev",
    "rust-lang.org",
    "kotlinlang.org",
    "swift.org",
    "php.net",
    "ruby-lang.org",
    "openjdk.org",
    "postgresql.org",
    "mysql.com",
    "redis.io",
    "mongodb.com",
    "docker.com",
    "kubernetes.io",
    "tensorflow.org",
    "pytorch.org",
    "scikit-learn.org",
    "huggingface.co",
    "learn.microsoft.com",
    "cloud.google.com",
    "aws.amazon.com",
}

STANDARDS_AND_SECURITY_HOSTS = {
    "w3.org",
    "whatwg.org",
    "ietf.org",
    "rfc-editor.org",
    "nist.gov",
    "cisa.gov",
    "mitre.org",
    "owasp.org",
    "enisa.europa.eu",
}

PROFESSIONAL_ORGANIZATION_HOSTS = {
    "acm.org",
    "ieee.org",
    "usenix.org",
    "cncf.io",
    "linuxfoundation.org",
}

ACADEMIC_AND_RESEARCH_HOSTS = {
    "arxiv.org",
    "dl.acm.org",
    "ieeexplore.ieee.org",
    "proceedings.neurips.cc",
    "openreview.net",
}

SURVEY_AND_REPORT_HOSTS = {
    "survey.stackoverflow.co",
    "stackoverflow.blog",
    "jetbrains.com",
    "stateofjs.com",
    "stateofcss.com",
    "github.blog",
    "thoughtworks.com",
}

STRONG_ENGINEERING_HOSTS = {
    "martinfowler.com",
    "cloudflare.com",
    "redhat.com",
    "mozilla.org",
    "engineering.fb.com",
    "netflixtechblog.com",
}

TRUSTED_NEWS_HOSTS = {
    "reuters.com",
    "arstechnica.com",
    "theregister.com",
    "technologyreview.com",
    "wired.com",
}

STRONG_CURRICULUM_HOSTS = {
    "roadmap.sh",
}

DISCOVERY_ONLY_HOSTS = {
    "reddit.com",
    "facebook.com",
    "linkedin.com",
    "medium.com",
    "quora.com",
    "pinterest.com",
    "tiktok.com",
    "instagram.com",
    "youtube.com",
    "youtu.be",
}


# =====================================================================
# TEXT SIGNALS
# =====================================================================

DOCUMENTATION_TERMS = {
    "documentation",
    "official docs",
    "developer guide",
    "reference",
    "manual",
    "specification",
}

STANDARD_TERMS = {
    "standard",
    "standards",
    "specification",
    "rfc",
    "guideline",
}

CURRICULUM_TERMS = {
    "curriculum",
    "syllabus",
    "roadmap",
    "learning path",
    "core competencies",
    "course outline",
}

SURVEY_TERMS = {
    "developer survey",
    "survey results",
    "industry survey",
    "industry report",
    "ecosystem report",
    "technology radar",
}

CONFERENCE_TERMS = {
    "conference",
    "keynote",
    "developer conference",
    "proceedings",
    "summit",
    "symposium",
}

JOB_MARKET_TERMS = {
    "job posting",
    "job postings",
    "hiring",
    "employer",
    "employers",
    "recruiter",
    "recruiters",
    "required skills",
    "skills demand",
    "in demand",
}

WEAK_SOURCE_TERMS = {
    "top 10",
    "top 20",
    "best technologies",
    "best frameworks",
    "ultimate guide",
    "salary guide",
    "must learn",
    "become a developer in",
}

LOW_VALUE_RESULT_TERMS = {
    "survey is live",
    "survey is now open",
    "help shape the",
    "survey is open",
    "take the survey",
}


# =====================================================================
# PAGE TEXT EXTRACTION
# =====================================================================

class PageTextExtractor(HTMLParser):
    BLOCK_TAGS = {
        "p",
        "li",
        "h1",
        "h2",
        "h3",
        "h4",
        "section",
        "article",
    }

    SKIP_TAGS = {
        "script",
        "style",
        "noscript",
        "svg",
    }

    def __init__(self) -> None:
        super().__init__()

        self.parts: list[str] = []
        self.skip_depth = 0

    def handle_starttag(
        self,
        tag: str,
        attrs: list[
            tuple[str, str | None]
        ],
    ) -> None:
        if tag in self.SKIP_TAGS:
            self.skip_depth += 1

        if (
            self.skip_depth == 0
            and tag in self.BLOCK_TAGS
        ):
            self.parts.append("\n")

    def handle_endtag(
        self,
        tag: str,
    ) -> None:
        if (
            tag in self.SKIP_TAGS
            and self.skip_depth > 0
        ):
            self.skip_depth -= 1

        if (
            self.skip_depth == 0
            and tag in self.BLOCK_TAGS
        ):
            self.parts.append("\n")

    def handle_data(
        self,
        data: str,
    ) -> None:
        if self.skip_depth > 0:
            return

        cleaned_data = data.strip()

        if cleaned_data:
            self.parts.append(
                cleaned_data
            )
            self.parts.append(" ")

    def get_text(self) -> str:
        return unescape(
            "".join(
                self.parts
            )
        )


# =====================================================================
# URL / HOST HELPERS
# =====================================================================

def get_hostname(
    url: str,
) -> str:
    hostname = (
        urlparse(url).hostname
        or ""
    ).casefold()

    if hostname.startswith(
        "www."
    ):
        hostname = hostname[4:]

    return hostname


def host_matches(
    hostname: str,
    suffixes: set[str],
) -> bool:
    return any(
        hostname == suffix
        or hostname.endswith(
            f".{suffix}"
        )
        for suffix in suffixes
    )


def normalize_url_for_comparison(
    url: str,
) -> str:
    parsed_url = urlparse(url)

    hostname = (
        parsed_url.hostname
        or ""
    ).casefold()

    path = (
        parsed_url.path
        .rstrip("/")
        .casefold()
    )

    return f"{hostname}{path}"


# =====================================================================
# SOURCE CLASSIFICATION
# =====================================================================

def infer_source_type(
    url: str,
    title: str,
    snippet: str,
) -> SourceType:
    hostname = get_hostname(
        url
    )

    searchable_text = (
        f"{title} {snippet}"
    ).casefold()

    path = urlparse(
        url
    ).path.casefold()

    if host_matches(
        hostname,
        DISCOVERY_ONLY_HOSTS,
    ):
        return "discovery_only"

    if host_matches(
        hostname,
        STANDARDS_AND_SECURITY_HOSTS,
    ):
        return "standards"

    if host_matches(
        hostname,
        PROFESSIONAL_ORGANIZATION_HOSTS,
    ):
        return "professional_org"

    if host_matches(
        hostname,
        ACADEMIC_AND_RESEARCH_HOSTS,
    ):
        return "academic"

    if host_matches(
        hostname,
        SURVEY_AND_REPORT_HOSTS,
    ):
        return "survey_report"

    if host_matches(
        hostname,
        TRUSTED_NEWS_HOSTS,
    ):
        return "news"

    if host_matches(
        hostname,
        STRONG_CURRICULUM_HOSTS,
    ):
        return "curriculum"

    if host_matches(
        hostname,
        STRONG_ENGINEERING_HOSTS,
    ):
        return "engineering"

    if host_matches(
        hostname,
        AUTHORITATIVE_TECHNICAL_HOSTS,
    ):
        return "official_docs"

    if any(
        term in searchable_text
        for term in CONFERENCE_TERMS
    ):
        return "conference"

    if any(
        term in searchable_text
        for term in SURVEY_TERMS
    ):
        return "survey_report"

    if any(
        term in searchable_text
        for term in CURRICULUM_TERMS
    ):
        return "curriculum"

    if any(
        term in searchable_text
        for term in JOB_MARKET_TERMS
    ):
        return "job_market"

    if (
        "docs" in hostname
        or "developer" in hostname
        or "/docs" in path
        or any(
            term in searchable_text
            for term in DOCUMENTATION_TERMS
        )
    ):
        return "official_docs"

    if any(
        term in searchable_text
        for term in STANDARD_TERMS
    ):
        return "standards"

    return "general"


def source_type_score(
    source_type: SourceType,
) -> int:
    scores = {
        "official_docs": 5,
        "standards": 5,
        "professional_org": 5,
        "academic": 4,
        "curriculum": 4,
        "survey_report": 4,
        "conference": 3,
        "engineering": 3,
        "news": 3,
        "job_market": 2,
        "general": 0,
        "discovery_only": -10,
    }

    return scores[
        source_type
    ]


def source_type_allowed_for_role(
    source_type: SourceType,
    evidence_role: EvidenceRole,
) -> bool:
    if source_type == "discovery_only":
        return False

    if evidence_role == "core":
        return source_type not in {
            "news",
            "job_market",
            "survey_report",
        }

    if evidence_role == "freshness":
        return source_type not in {
            "job_market",
        }

    return source_type not in {
        "curriculum",
        "academic",
    }


def classify_source(
    url: str,
    title: str,
    snippet: str,
    published_date: str | None,
    evidence_role: EvidenceRole,
    requested_source_type: SourceType,
) -> dict[str, Any]:
    hostname = get_hostname(
        url
    )

    source_type = infer_source_type(
        url=url,
        title=title,
        snippet=snippet,
    )

    if source_type == "discovery_only":
        return {
            "source_type": source_type,
            "source_score": -10,
            "source_quality": (
                "discovery_only"
            ),
            "primary_eligible": False,
            "quality_reasons": [
                (
                    "Community or social source; "
                    "not used as primary evidence."
                )
            ],
        }

    score = source_type_score(
        source_type
    )

    reasons = [
        (
            "Source type classified as "
            f"{source_type}."
        )
    ]

    if hostname.endswith(
        ".gov"
    ):
        score += 4
        reasons.append(
            "Government source."
        )

    elif hostname.endswith(
        ".edu"
    ):
        score += 2
        reasons.append(
            "Academic institution domain."
        )

    if (
        source_type
        == requested_source_type
    ):
        score += 1

        reasons.append(
            (
                "Source type matches the "
                "research purpose."
            )
        )

    searchable_text = (
        f"{title} {snippet}"
    ).casefold()

    if any(
        term in searchable_text
        for term in WEAK_SOURCE_TERMS
    ):
        score -= 2

        reasons.append(
            (
                "Possible listicle, promotional, "
                "or SEO-style wording."
            )
        )

    current_year = datetime.now(
        timezone.utc
    ).year

    previous_year = (
        current_year - 1
    )

    date_text = (
        f"{published_date or ''} "
        f"{title}"
    ).casefold()

    if evidence_role in {
        "freshness",
        "market",
    }:
        if str(
            current_year
        ) in date_text:
            score += 2

            reasons.append(
                "Current-year evidence."
            )

        elif str(
            previous_year
        ) in date_text:
            score += 1

            reasons.append(
                "Recent evidence."
            )

    role_eligible = (
        source_type_allowed_for_role(
            source_type=source_type,
            evidence_role=evidence_role,
        )
    )

    if not role_eligible:
        reasons.append(
            (
                "Source type is not appropriate "
                f"for the {evidence_role} role."
            )
        )

    if score >= 8:
        quality = "high_confidence"

    elif score >= 5:
        quality = "strong"

    elif score >= 2:
        quality = "useful"

    else:
        quality = "unclassified"

    return {
        "source_type": source_type,
        "source_score": score,
        "source_quality": quality,
        "primary_eligible": (
            role_eligible
        ),
        "quality_reasons": reasons,
    }


# =====================================================================
# SEARCH PLAN
# =====================================================================

def build_current_domain_queries(
    roadmap_topic: str,
) -> list[
    tuple[
        ResearchCategory,
        EvidenceRole,
        SourceType,
        str,
    ]
]:
    topic = roadmap_topic.strip()

    current_year = datetime.now(
        timezone.utc
    ).year

    previous_year = (
        current_year - 1
    )

    return [
        (
            "domain",
            "core",
            "curriculum",
            (
                f'"{topic}" curriculum roadmap '
                "core competencies professional"
            ),
        ),
        (
            "domain",
            "core",
            "official_docs",
            (
                f'"{topic}" official documentation '
                "standards fundamentals core concepts"
            ),
        ),
        (
            "domain",
            "freshness",
            "survey_report",
            (
                f'"{topic}" developer survey '
                f"industry report current technologies "
                f"{previous_year} {current_year}"
            ),
        ),
        (
            "domain",
            "freshness",
            "engineering",
            (
                f'"{topic}" production engineering '
                f"professional practices {current_year}"
            ),
        ),
    ]


# =====================================================================
# SEARCH
# =====================================================================

def search_web(
    query: str,
    category: ResearchCategory,
    evidence_role: EvidenceRole | None = None,
    requested_source_type: SourceType | None = None,
) -> list[dict[str, Any]]:
    api_key = os.getenv(
        "SERPER_API_KEY"
    )

    if not api_key:
        raise ValueError(
            "Missing SERPER_API_KEY in .env."
        )

    resolved_role: EvidenceRole = (
        evidence_role
        or (
            "market"
            if category == "market"
            else "core"
        )
    )

    resolved_source_type: SourceType = (
        requested_source_type
        or (
            "job_market"
            if category == "market"
            else "general"
        )
    )

    try:
        response = requests.post(
            SERPER_SEARCH_URL,
            headers={
                "X-API-KEY": api_key,
                "Content-Type": (
                    "application/json"
                ),
            },
            json={
                "q": query,
                "num": RESULTS_PER_QUERY,
            },
            timeout=15,
        )

        response.raise_for_status()

    except requests.exceptions.RequestException as error:
        raise RuntimeError(
            (
                "Roadmap current-domain "
                f"research failed: {error}"
            )
        ) from error

    organic_results = (
        response.json().get(
            "organic",
            [],
        )
    )

    results: list[
        dict[str, Any]
    ] = []

    for item in organic_results:
        title = item.get(
            "title",
            "",
        ).strip()

        url = item.get(
            "link",
            "",
        ).strip()

        snippet = item.get(
            "snippet",
            "",
        ).strip()

        published_date = item.get(
            "date"
        )

        if not title or not url:
            continue

        source_classification = (
            classify_source(
                url=url,
                title=title,
                snippet=snippet,
                published_date=(
                    published_date
                ),
                evidence_role=(
                    resolved_role
                ),
                requested_source_type=(
                    resolved_source_type
                ),
            )
        )

        hostname = get_hostname(
            url
        )

        results.append(
            {
                "category": category,
                "evidence_role": (
                    resolved_role
                ),
                "requested_source_type": (
                    resolved_source_type
                ),
                "source_name": (
                    item.get("source")
                    or hostname
                    or "Web Source"
                ),
                "title": title,
                "url": url,
                "published_date": (
                    published_date
                ),
                "snippet": snippet,
                "search_query": query,
                **source_classification,
            }
        )

    return results


# =====================================================================
# RESULT FILTERING
# =====================================================================

def remove_duplicate_results(
    results: list[
        dict[str, Any]
    ],
) -> list[dict[str, Any]]:
    unique_results: list[
        dict[str, Any]
    ] = []

    seen_urls: set[str] = set()

    for result in results:
        normalized_url = (
            normalize_url_for_comparison(
                result["url"]
            )
        )

        if normalized_url in seen_urls:
            continue

        seen_urls.add(
            normalized_url
        )

        unique_results.append(
            result
        )

    return unique_results


def is_low_value_result(
    result: dict[str, Any],
) -> bool:
    searchable_text = (
        f"{result['title']} "
        f"{result.get('snippet', '')}"
    ).casefold()

    return any(
        term in searchable_text
        for term in LOW_VALUE_RESULT_TERMS
    )


def source_role_priority(
    source_type: SourceType,
    evidence_role: EvidenceRole,
) -> int:
    priorities: dict[
        EvidenceRole,
        list[SourceType],
    ] = {
        "core": [
            "official_docs",
            "standards",
            "professional_org",
            "curriculum",
            "academic",
            "engineering",
            "conference",
            "general",
            "survey_report",
            "news",
            "job_market",
            "discovery_only",
        ],
        "freshness": [
            "official_docs",
            "survey_report",
            "conference",
            "professional_org",
            "engineering",
            "standards",
            "academic",
            "news",
            "general",
            "curriculum",
            "job_market",
            "discovery_only",
        ],
        "market": [
            "job_market",
            "survey_report",
            "news",
            "professional_org",
            "engineering",
            "general",
            "official_docs",
            "conference",
            "standards",
            "curriculum",
            "academic",
            "discovery_only",
        ],
    }

    try:
        return priorities[
            evidence_role
        ].index(
            source_type
        )

    except ValueError:
        return 999


def select_evidence(
    results: list[
        dict[str, Any]
    ],
    category: ResearchCategory,
    limit: int,
    evidence_role: EvidenceRole | None = None,
) -> list[dict[str, Any]]:
    category_results = [
        result
        for result in results
        if (
            result["category"]
            == category
            and result[
                "primary_eligible"
            ]
            and not is_low_value_result(
                result
            )
            and (
                evidence_role is None
                or result[
                    "evidence_role"
                ] == evidence_role
            )
        )
    ]

    category_results.sort(
        key=lambda result: (
            -result[
                "source_score"
            ],
            source_role_priority(
                result[
                    "source_type"
                ],
                result[
                    "evidence_role"
                ],
            ),
            get_hostname(
                result["url"]
            ),
            normalize_url_for_comparison(
                result["url"]
            ),
        )
    )

    selected_results: list[
        dict[str, Any]
    ] = []

    host_counts: dict[
        str,
        int,
    ] = {}

    for result in category_results:
        hostname = get_hostname(
            result["url"]
        )

        current_host_count = (
            host_counts.get(
                hostname,
                0,
            )
        )

        if (
            current_host_count
            >= MAX_SOURCES_PER_HOST
        ):
            continue

        selected_results.append(
            result
        )

        host_counts[
            hostname
        ] = (
            current_host_count
            + 1
        )

        if (
            len(selected_results)
            >= limit
        ):
            break

    return selected_results


# =====================================================================
# FETCH PAGE
# =====================================================================

def fetch_page_text(
    url: str,
) -> str:
    try:
        response = requests.get(
            url,
            headers={
                "User-Agent": (
                    "Mozilla/5.0 "
                    "(compatible; "
                    "CapstoneRoadmapResearch/3.0)"
                )
            },
            timeout=(
                4,
                4,
            ),
            stream=True,
        )

        response.raise_for_status()

        content_type = (
            response.headers.get(
                "Content-Type",
                "",
            ).casefold()
        )

        if (
            "text/html"
            not in content_type
            and "text/plain"
            not in content_type
        ):
            response.close()
            return ""

        start_time = monotonic()

        downloaded = bytearray()

        for chunk in response.iter_content(
            chunk_size=8192
        ):
            if not chunk:
                continue

            downloaded.extend(
                chunk
            )

            if (
                len(downloaded)
                >= MAX_PAGE_BYTES
            ):
                break

            if (
                monotonic()
                - start_time
                >= MAX_PAGE_FETCH_SECONDS
            ):
                break

        response.close()

    except requests.exceptions.RequestException:
        return ""

    if not downloaded:
        return ""

    encoding = (
        response.encoding
        or "utf-8"
    )

    try:
        page_html = bytes(
            downloaded
        ).decode(
            encoding,
            errors="ignore",
        )

    except LookupError:
        page_html = bytes(
            downloaded
        ).decode(
            "utf-8",
            errors="ignore",
        )

    extractor = (
        PageTextExtractor()
    )

    try:
        extractor.feed(
            page_html
        )

    except Exception:
        return ""

    return extractor.get_text()

# =====================================================================
# TOPIC RELEVANCE
# =====================================================================

def build_topic_keywords(
    roadmap_topic: str,
) -> set[str]:
    raw_words = re.findall(
        r"[A-Za-z0-9+#.]+",
        roadmap_topic.casefold(),
    )

    generic_words = {
        "development",
        "developer",
        "developers",
        "engineering",
        "engineer",
        "engineers",
        "computer",
        "computing",
        "science",
        "technology",
        "technologies",
        "professional",
        "for",
        "and",
        "of",
        "in",
        "to",
        "with",
    }

    specific_words = {
        word
        for word in raw_words
        if word not in generic_words
    }

    if not specific_words:
        specific_words = set(
            raw_words
        )

    return specific_words


def keyword_in_text(
    keyword: str,
    text: str,
) -> bool:
    lowered_text = (
        text.casefold()
    )

    compact_text = re.sub(
        r"[-\s_/]+",
        "",
        lowered_text,
    )

    compact_keyword = re.sub(
        r"[-\s_/]+",
        "",
        keyword.casefold(),
    )

    if (
        len(compact_keyword) <= 2
        and compact_keyword.isalnum()
    ):
        pattern = (
            r"\b"
            + re.escape(
                compact_keyword
            )
            + r"\b"
        )

        return bool(
            re.search(
                pattern,
                lowered_text,
            )
        )

    return (
        compact_keyword
        in compact_text
    )


def text_matches_topic(
    text: str,
    roadmap_topic: str,
) -> bool:
    keywords = build_topic_keywords(
        roadmap_topic
    )

    return any(
        keyword_in_text(
            keyword,
            text,
        )
        for keyword in keywords
    )


def build_role_keywords(
    evidence_role: EvidenceRole,
) -> set[str]:
    if evidence_role == "core":
        return {
            "fundamental",
            "fundamentals",
            "concept",
            "concepts",
            "curriculum",
            "roadmap",
            "skill",
            "skills",
            "competency",
            "competencies",
            "standard",
            "documentation",
            "architecture",
            "professional",
        }

    if evidence_role == "freshness":
        return {
            "current",
            "modern",
            "adoption",
            "adopted",
            "survey",
            "report",
            "conference",
            "release",
            "production",
            "professional",
            "ecosystem",
            "industry",
        }

    return {
        "job",
        "jobs",
        "hiring",
        "employer",
        "employers",
        "recruiter",
        "recruiters",
        "demand",
        "required",
        "requirements",
        "skills",
    }


# =====================================================================
# RELEVANT PAGE EXCERPT
# =====================================================================

def extract_relevant_excerpt(
    page_text: str,
    roadmap_topic: str,
    evidence_role: EvidenceRole,
) -> str:
    if not page_text.strip():
        return ""

    topic_keywords = (
        build_topic_keywords(
            roadmap_topic
        )
    )

    role_keywords = (
        build_role_keywords(
            evidence_role
        )
    )

    raw_blocks = [
        re.sub(
            r"\s+",
            " ",
            block,
        ).strip()
        for block in page_text.splitlines()
    ]

    candidate_blocks: list[
        tuple[
            int,
            int,
            str,
        ]
    ] = []

    for index, block in enumerate(
        raw_blocks
    ):
        if len(block) < 40:
            continue

        topic_matches = sum(
            1
            for keyword in topic_keywords
            if keyword_in_text(
                keyword,
                block,
            )
        )

        if topic_matches == 0:
            continue

        lowered_block = (
            block.casefold()
        )

        role_matches = sum(
            1
            for keyword in role_keywords
            if keyword in lowered_block
        )

        if (
            evidence_role == "market"
            and role_matches == 0
        ):
            continue

        score = (
            topic_matches * 4
            + role_matches
        )

        candidate_blocks.append(
            (
                score,
                index,
                block,
            )
        )

    candidate_blocks.sort(
        key=lambda item: (
            -item[0],
            item[1],
        )
    )

    selected_blocks: list[
        str
    ] = []

    current_length = 0

    for (
        _,
        _,
        block,
    ) in candidate_blocks:
        if block in selected_blocks:
            continue

        projected_length = (
            current_length
            + len(block)
        )

        if (
            projected_length
            > MAX_EXCERPT_CHARACTERS
        ):
            continue

        selected_blocks.append(
            block
        )

        current_length = (
            projected_length
        )

        if (
            len(selected_blocks)
            >= 4
        ):
            break

    return "\n".join(
        selected_blocks
    )


# =====================================================================
# EVIDENCE ENRICHMENT
# =====================================================================

def enrich_evidence(
    roadmap_topic: str,
    evidence_items: list[
        dict[str, Any]
    ],
) -> list[dict[str, Any]]:
    enriched_items: list[
        dict[str, Any]
    ] = []

    for item in evidence_items:
        page_text = fetch_page_text(
            item["url"]
        )

        page_excerpt = (
            extract_relevant_excerpt(
                page_text=page_text,
                roadmap_topic=(
                    roadmap_topic
                ),
                evidence_role=(
                    item[
                        "evidence_role"
                    ]
                ),
            )
        )

        snippet = item.get(
            "snippet",
            "",
        )

        if page_excerpt:
            evidence_text = (
                page_excerpt
            )

        elif text_matches_topic(
            snippet,
            roadmap_topic,
        ):
            evidence_text = (
                snippet
            )

        else:
            continue

        enriched_items.append(
            {
                **item,
                "evidence_text": (
                    evidence_text
                ),
                "page_content_retrieved": (
                    bool(
                        page_excerpt
                    )
                ),
            }
        )

    return enriched_items

def enrich_evidence_until_limit(
    roadmap_topic: str,
    evidence_items: list[
        dict[str, Any]
    ],
    limit: int,
) -> list[dict[str, Any]]:
    enriched_items: list[
        dict[str, Any]
    ] = []

    for item in evidence_items:
        page_text = fetch_page_text(
            item["url"]
        )

        page_excerpt = (
            extract_relevant_excerpt(
                page_text=page_text,
                roadmap_topic=(
                    roadmap_topic
                ),
                evidence_role=(
                    item[
                        "evidence_role"
                    ]
                ),
            )
        )

        snippet = item.get(
            "snippet",
            "",
        )

        if page_excerpt:
            evidence_text = (
                page_excerpt
            )

        elif text_matches_topic(
            snippet,
            roadmap_topic,
        ):
            evidence_text = (
                snippet
            )

        else:
            continue

        enriched_items.append(
            {
                **item,
                "evidence_text": (
                    evidence_text
                ),
                "page_content_retrieved": (
                    bool(
                        page_excerpt
                    )
                ),
            }
        )

        if (
            len(enriched_items)
            >= limit
        ):
            break

    return enriched_items

# =====================================================================
# SUMMARY
# =====================================================================

def format_evidence_section(
    title: str,
    evidence_items: list[
        dict[str, Any]
    ],
) -> str:
    lines = [
        title
    ]

    if not evidence_items:
        lines.append(
            (
                "No sufficiently relevant "
                "evidence was retrieved."
            )
        )

        return "\n".join(
            lines
        )

    for index, item in enumerate(
        evidence_items,
        start=1,
    ):
        reasons = "; ".join(
            item[
                "quality_reasons"
            ]
        )

        lines.extend(
            [
                "",
                f"Evidence {index}",
                (
                    "Source: "
                    f"{item['source_name']}"
                ),
                (
                    "Source type: "
                    f"{item['source_type']}"
                ),
                (
                    "Quality: "
                    f"{item['source_quality']}"
                ),
                (
                    "Quality reasons: "
                    f"{reasons}"
                ),
                (
                    "Title: "
                    f"{item['title']}"
                ),
                (
                    "Published date: "
                    f"{item.get('published_date')}"
                ),
                (
                    "URL: "
                    f"{item['url']}"
                ),
                "Evidence text:",
                item[
                    "evidence_text"
                ],
            ]
        )

    return "\n".join(
        lines
    )


def build_research_summary(
    roadmap_topic: str,
    research_year: int,
    core_evidence: list[
        dict[str, Any]
    ],
    freshness_evidence: list[
        dict[str, Any]
    ],
) -> str:
    core_section = (
        format_evidence_section(
            title=(
                "SUPPORTING CORE REFERENCES"
            ),
            evidence_items=(
                core_evidence
            ),
        )
    )

    freshness_section = (
        format_evidence_section(
            title=(
                "CURRENT-PRACTICE EVIDENCE "
                f"FOR {research_year}"
            ),
            evidence_items=(
                freshness_evidence
            ),
        )
    )

    return (
        f"ROADMAP TOPIC: "
        f"{roadmap_topic}\n"
        f"ROADMAP YEAR: "
        f"{research_year}\n\n"

        "RESEARCH ROLE\n\n"

        "This research is supporting evidence, "
        "not an exhaustive curriculum.\n\n"

        "Build the stable professional curriculum "
        "using established Computer Science and "
        "software-engineering knowledge.\n\n"

        "Use these sources to verify that the "
        "curriculum remains current, identify "
        "important modern ecosystem changes, "
        "avoid obsolete recommendations, and "
        "confirm current professional relevance.\n\n"

        "Do not omit a foundational competency "
        "merely because it is absent from these "
        "search results.\n\n"

        "Do not create a roadmap node solely "
        "because one retrieved source mentions "
        "a technology or practice.\n\n"

        f"{core_section}\n\n"
        f"{freshness_section}"
    )


# =====================================================================
# MAIN RESEARCH FUNCTION
# =====================================================================

def research_current_domain(
    roadmap_topic: str,
) -> dict[str, Any]:
    topic = roadmap_topic.strip()

    if not topic:
        raise ValueError(
            "roadmap_topic is required."
        )

    research_year = datetime.now(
        timezone.utc
    ).year

    queries = (
        build_current_domain_queries(
            topic
        )
    )

    all_results: list[
        dict[str, Any]
    ] = []

    search_warnings: list[
        str
    ] = []

    for (
        category,
        evidence_role,
        source_type,
        query,
    ) in queries:
        try:
            query_results = (
                search_web(
                    query=query,
                    category=category,
                    evidence_role=(
                        evidence_role
                    ),
                    requested_source_type=(
                        source_type
                    ),
                )
            )

            all_results.extend(
                query_results
            )

        except RuntimeError as error:
            search_warnings.append(
                str(error)
            )

    unique_results = (
        remove_duplicate_results(
            all_results
        )
    )

    core_candidates = (
        select_evidence(
            results=(
                unique_results
            ),
            category="domain",
            evidence_role="core",
            limit=CANDIDATE_LIMIT,
        )
    )

    freshness_candidates = (
        select_evidence(
            results=(
                unique_results
            ),
            category="domain",
            evidence_role="freshness",
            limit=CANDIDATE_LIMIT,
        )
    )

    core_evidence = (
        enrich_evidence_until_limit(
            roadmap_topic=topic,
            evidence_items=(
                core_candidates
            ),
            limit=(
                MAX_CORE_EVIDENCE
            ),
        )
    )

    freshness_evidence = (
        enrich_evidence_until_limit(
            roadmap_topic=topic,
            evidence_items=(
                freshness_candidates
            ),
            limit=(
                MAX_FRESHNESS_EVIDENCE
            ),
        )
    )

    if (
        not core_evidence
        and not freshness_evidence
    ):
        fallback_warning = (
            "No sufficiently relevant external "
            "research evidence was extracted for "
            f"'{topic}'. The Roadmap Builder should "
            "use established Computer Science and "
            "software-engineering knowledge for the "
            "stable curriculum and treat currentness "
            "as unverified for this generation."
        )

        search_warnings.append(
            fallback_warning
        )

    domain_evidence = (
        core_evidence
        + freshness_evidence
    )

    summary = (
        build_research_summary(
            roadmap_topic=topic,
            research_year=(
                research_year
            ),
            core_evidence=(
                core_evidence
            ),
            freshness_evidence=(
                freshness_evidence
            ),
        )
    )

    sources: list[
        str
    ] = []

    seen_sources: set[
        str
    ] = set()

    for evidence_item in (
        domain_evidence
    ):
        url = evidence_item[
            "url"
        ]

        normalized_url = (
            normalize_url_for_comparison(
                url
            )
        )

        if (
            normalized_url
            in seen_sources
        ):
            continue

        seen_sources.add(
            normalized_url
        )

        sources.append(
            url
        )

    return {
        "research_version": (
            RESEARCH_VERSION
        ),
        "research_year": (
            research_year
        ),
        "roadmap_topic": topic,
        "summary": summary,
        "core_evidence": (
            core_evidence
        ),
        "freshness_evidence": (
            freshness_evidence
        ),
        "domain_evidence": (
            domain_evidence
        ),
        "market_evidence": [],
        "sources": sources,
        "search_warnings": (
            search_warnings
        ),
    }


# =====================================================================
# MANUAL TEST
# =====================================================================

if __name__ == "__main__":
    result = (
        research_current_domain(
            "Backend Development"
        )
    )

    print(
        result["summary"]
    )

    print(
        "\nSOURCES\n"
    )

    for source in result[
        "sources"
    ]:
        print(source)

    if result[
        "search_warnings"
    ]:
        print(
            "\nSEARCH WARNINGS\n"
        )

        for warning in result[
            "search_warnings"
        ]:
            print(warning)