import base64
import binascii
import csv
import io
import json
import os
import tempfile
import zipfile
from pathlib import Path, PurePosixPath
from typing import Any
from urllib.parse import urlparse
import requests


# =====================================================================
# SUPPORTED SUBMISSION FILES
# =====================================================================

ALLOWED_SUBMISSION_EXTENSIONS = {
    ".zip",
    ".sql",
    ".py",
    ".ipynb",
    ".csv",
    ".png",
    ".jpg",
    ".jpeg",
    ".pdf",
    ".txt",
    ".md",
}


# Source and configuration files that may appear inside a ZIP.
ZIP_TEXT_EXTENSIONS = {
    ".py",
    ".js",
    ".jsx",
    ".ts",
    ".tsx",
    ".java",
    ".c",
    ".cpp",
    ".h",
    ".hpp",
    ".cs",
    ".php",
    ".rb",
    ".go",
    ".rs",
    ".swift",
    ".kt",
    ".kts",
    ".dart",
    ".html",
    ".css",
    ".scss",
    ".sql",
    ".txt",
    ".md",
    ".json",
    ".yaml",
    ".yml",
    ".xml",
    ".sh",
    ".ps1",
}


ZIP_VISUAL_EXTENSIONS = {
    ".png",
    ".jpg",
    ".jpeg",
    ".pdf",
}


VISUAL_MEDIA_TYPES = {
    ".png": "image/png",
    ".jpg": "image/jpeg",
    ".jpeg": "image/jpeg",
    ".pdf": "application/pdf",
}


SKIPPED_DIRECTORY_NAMES = {
    ".git",
    ".idea",
    ".vscode",
    ".venv",
    "venv",
    "env",
    "node_modules",
    "__pycache__",
    "dist",
    "build",
    "coverage",
    ".next",
}


SKIPPED_FILE_NAMES = {
    "package-lock.json",
    "yarn.lock",
    "pnpm-lock.yaml",
}


MAX_TEXT_CHARS_PER_FILE = 12000
MAX_TOTAL_TEXT_CHARS = 60000

MAX_ARCHIVE_MEMBER_BYTES = 2_000_000
MAX_NOTEBOOK_BYTES = 15_000_000
MAX_CSV_BYTES = 20_000_000
MAX_VISUAL_BYTES = 10_000_000

CSV_SAMPLE_ROWS = 5

GITHUB_API_REPOSITORY_URL = (
    "https://api.github.com/repos/"
    "{owner}/{repository}"
)

GITHUB_API_ARCHIVE_URL = (
    "https://api.github.com/repos/"
    "{owner}/{repository}/zipball/{ref}"
)

GITHUB_REQUEST_TIMEOUT_SECONDS = 30

MAX_GITHUB_ARCHIVE_BYTES = (
    50 * 1024 * 1024
)


# =====================================================================
# NOTEBOOK EXTRACTION
# =====================================================================

def extract_notebook_evidence(
    notebook_bytes: bytes,
) -> dict[str, Any]:

    notebook = json.loads(
        notebook_bytes.decode(
            "utf-8",
            errors="replace",
        )
    )

    text_parts: list[str] = []

    visual_outputs: list[
        dict[str, Any]
    ] = []

    cells = notebook.get(
        "cells",
        [],
    )

    for index, cell in enumerate(
        cells,
        start=1,
    ):

        cell_type = cell.get(
            "cell_type"
        )

        source = cell.get(
            "source",
            [],
        )

        if isinstance(
            source,
            list,
        ):
            source_text = "".join(
                str(part)
                for part in source
            )

        else:
            source_text = str(
                source
            )

        source_text = (
            source_text.strip()
        )

        if cell_type == "markdown":

            if source_text:
                text_parts.append(
                    f"[MARKDOWN CELL {index}]\n"
                    f"{source_text}"
                )

            continue

        if cell_type != "code":
            continue

        if source_text:
            text_parts.append(
                f"[CODE CELL {index}]\n"
                f"{source_text}"
            )

        outputs = cell.get(
            "outputs",
            [],
        )

        output_parts: list[str] = []

        for output_index, output in enumerate(
            outputs,
            start=1,
        ):

            output_type = output.get(
                "output_type"
            )

            if output_type == "stream":

                output_text = output.get(
                    "text",
                    [],
                )

                if isinstance(
                    output_text,
                    list,
                ):
                    output_text = "".join(
                        str(part)
                        for part in output_text
                    )

                output_text = str(
                    output_text
                ).strip()

                if output_text:
                    output_parts.append(
                        output_text
                    )

            elif output_type in {
                "execute_result",
                "display_data",
            }:

                data = output.get(
                    "data",
                    {},
                )

                plain_text = data.get(
                    "text/plain"
                )

                if isinstance(
                    plain_text,
                    list,
                ):
                    plain_text = "".join(
                        str(part)
                        for part in plain_text
                    )

                if (
                    plain_text is not None
                ):

                    plain_text = str(
                        plain_text
                    ).strip()

                    if plain_text:
                        output_parts.append(
                            plain_text
                        )

                image_data = data.get(
                    "image/png"
                )

                media_type = "image/png"
                file_extension = ".png"

                if image_data is None:

                    image_data = data.get(
                        "image/jpeg"
                    )

                    media_type = (
                        "image/jpeg"
                    )

                    file_extension = ".jpg"

                if image_data is not None:

                    if isinstance(
                        image_data,
                        list,
                    ):
                        image_data = "".join(
                            str(part)
                            for part
                            in image_data
                        )

                    try:
                        image_bytes = (
                            base64.b64decode(
                                str(
                                    image_data
                                )
                            )
                        )

                    except (
                        ValueError,
                        TypeError,
                        binascii.Error,
                    ):
                        image_bytes = b""

                    if image_bytes:
                        visual_outputs.append(
                            {
                                "cell_index": (
                                    index
                                ),
                                "output_index": (
                                    output_index
                                ),
                                "media_type": (
                                    media_type
                                ),
                                "file_extension": (
                                    file_extension
                                ),
                                "image_bytes": (
                                    image_bytes
                                ),
                            }
                        )

            elif output_type == "error":

                error_name = output.get(
                    "ename",
                    "Error",
                )

                error_value = output.get(
                    "evalue",
                    "",
                )

                output_parts.append(
                    f"{error_name}: "
                    f"{error_value}"
                )

        if output_parts:
            text_parts.append(
                f"[OUTPUT CELL {index}]\n"
                + "\n".join(
                    output_parts
                )
            )

    return {
        "text": "\n\n".join(
            text_parts
        ),
        "visual_outputs": (
            visual_outputs
        ),
    }


# =====================================================================
# CSV EXTRACTION
# =====================================================================

def extract_csv_evidence(
    csv_bytes: bytes,
    file_name: str,
) -> str:

    csv_text = csv_bytes.decode(
        "utf-8-sig",
        errors="replace",
    )

    reader = csv.reader(
        io.StringIO(
            csv_text
        )
    )

    try:
        header = next(
            reader
        )

    except StopIteration:
        return (
            f"[CSV DATASET: {file_name}]\n"
            "The CSV file is empty."
        )

    row_count = 0

    sample_rows: list[
        list[str]
    ] = []

    for row in reader:

        row_count += 1

        if (
            len(sample_rows)
            < CSV_SAMPLE_ROWS
        ):
            sample_rows.append(
                row
            )

    evidence_parts = [
        f"[CSV DATASET: {file_name}]",
        f"Row count: {row_count}",
        f"Column count: {len(header)}",
        "Columns:",
        ", ".join(
            header
        ),
    ]

    if sample_rows:

        evidence_parts.append(
            "Sample rows:"
        )

        for index, row in enumerate(
            sample_rows,
            start=1,
        ):

            shortened_row = [
                value[:200]
                for value in row
            ]

            evidence_parts.append(
                f"Row {index}: "
                + " | ".join(
                    shortened_row
                )
            )

    return "\n".join(
        evidence_parts
    )


# =====================================================================
# ZIP FILTERING
# =====================================================================

def should_skip_archive_member(
    member_name: str,
) -> bool:

    path = PurePosixPath(
        member_name
    )

    if (
        path.is_absolute()
        or ".." in path.parts
    ):
        raise ValueError(
            "Unsafe path found inside "
            "submission ZIP."
        )

    lowered_parts = {
        part.lower()
        for part in path.parts
    }

    if (
        lowered_parts
        & SKIPPED_DIRECTORY_NAMES
    ):
        return True

    filename = path.name.lower()

    if filename in SKIPPED_FILE_NAMES:
        return True

    if filename.startswith(".env"):
        return True

    return False


# =====================================================================
# TEXT EVIDENCE
# =====================================================================

def build_text_evidence(
    file_name: str,
    text: str,
    remaining_chars: int,
) -> tuple[
    dict[str, Any],
    int,
]:

    clean_text = text.strip()

    allowed_chars = min(
        MAX_TEXT_CHARS_PER_FILE,
        remaining_chars,
    )

    truncated_text = (
        clean_text[
            :allowed_chars
        ]
    )

    evidence = {
        "file_name": file_name,
        "content": truncated_text,
        "is_truncated": (
            len(clean_text)
            > len(truncated_text)
        ),
        "original_char_count": (
            len(clean_text)
        ),
        "included_char_count": (
            len(truncated_text)
        ),
    }

    return (
        evidence,
        len(truncated_text),
    )

# =====================================================================
# GITHUB SUBMISSION
# =====================================================================

def parse_github_repository_url(
    repository_url: str,
) -> tuple[str, str]:

    if (
        not isinstance(
            repository_url,
            str,
        )
        or not repository_url.strip()
    ):
        raise ValueError(
            "GitHub repository URL is required."
        )

    repository_url = (
        repository_url.strip()
    )

    parsed_url = urlparse(
        repository_url
    )

    if (
        parsed_url.scheme
        not in {
            "http",
            "https",
        }
    ):
        raise ValueError(
            "Invalid GitHub repository URL."
        )

    hostname = (
        parsed_url.netloc
        .strip()
        .lower()
    )

    if hostname not in {
        "github.com",
        "www.github.com",
    }:
        raise ValueError(
            "Submission URL must be a "
            "GitHub repository URL."
        )

    path_parts = [
        part
        for part in (
            parsed_url.path
            .strip("/")
            .split("/")
        )
        if part
    ]

    if len(path_parts) != 2:
        raise ValueError(
            "GitHub submission URL must "
            "point to the repository root."
        )

    owner = (
        path_parts[0]
        .strip()
    )

    repository = (
        path_parts[1]
        .strip()
    )

    if repository.endswith(
        ".git"
    ):
        repository = (
            repository[:-4]
        )

    if (
        not owner
        or not repository
    ):
        raise ValueError(
            "Invalid GitHub repository URL."
        )

    return (
        owner,
        repository,
    )


def build_github_headers(
) -> dict[str, str]:

    headers = {
        "Accept": (
            "application/vnd.github+json"
        ),
    }

    github_token = os.getenv("GITHUB_TOKEN")

    if github_token:
        headers[
            "Authorization"
        ] = (
            f"Bearer {github_token}"
        )

    return headers


def fetch_github_repository_metadata(
    owner: str,
    repository: str,
) -> dict[str, Any]:

    repository_api_url = (
        GITHUB_API_REPOSITORY_URL.format(
            owner=owner,
            repository=repository,
        )
    )

    try:
        response = requests.get(
            repository_api_url,
            headers=(
                build_github_headers()
            ),
            timeout=(
                GITHUB_REQUEST_TIMEOUT_SECONDS
            ),
        )

    except requests.RequestException as error:
        raise RuntimeError(
            "Could not connect to GitHub "
            "to verify the repository."
        ) from error

    if response.status_code == 404:
        raise ValueError(
            "GitHub repository was not found "
            "or is not publicly accessible. "
            "Only public GitHub repositories "
            "can be assessed."
        )

    if response.status_code in {
        401,
        403,
    }:
        raise RuntimeError(
            "GitHub repository verification "
            "failed because GitHub API access "
            "was denied or rate-limited."
        )

    try:
        response.raise_for_status()

    except requests.RequestException as error:
        raise RuntimeError(
            "GitHub repository verification "
            "failed."
        ) from error

    repository_data = (
        response.json()
    )

    if repository_data.get(
        "private",
        False,
    ):
        raise ValueError(
            "Private GitHub repositories "
            "cannot be assessed. "
            "Only public GitHub repositories "
            "are visible to the AI system."
        )

    default_branch = (
        repository_data.get(
            "default_branch"
        )
    )

    if (
        not isinstance(
            default_branch,
            str,
        )
        or not default_branch.strip()
    ):
        raise ValueError(
            "Could not determine the "
            "repository default branch."
        )

    return repository_data


def download_github_repository_archive(
    owner: str,
    repository: str,
    default_branch: str,
    destination_path: Path,
) -> None:

    archive_url = (
        GITHUB_API_ARCHIVE_URL.format(
            owner=owner,
            repository=repository,
            ref=default_branch,
        )
    )

    try:
        response = requests.get(
            archive_url,
            headers=(
                build_github_headers()
            ),
            timeout=(
                GITHUB_REQUEST_TIMEOUT_SECONDS
            ),
            stream=True,
        )

    except requests.RequestException as error:
        raise RuntimeError(
            "Could not download the "
            "GitHub repository."
        ) from error

    if response.status_code == 404:
        raise ValueError(
            "GitHub repository archive "
            "could not be accessed. "
            "Only public GitHub repositories "
            "can be assessed."
        )

    try:
        response.raise_for_status()

    except requests.RequestException as error:
        raise RuntimeError(
            "GitHub repository download "
            "failed."
        ) from error

    content_length = (
        response.headers.get(
            "Content-Length"
        )
    )

    if content_length is not None:

        try:
            archive_size = int(
                content_length
            )

        except ValueError:
            archive_size = None

        if (
            archive_size is not None
            and archive_size
            > MAX_GITHUB_ARCHIVE_BYTES
        ):
            raise ValueError(
                "GitHub repository is too "
                "large to assess."
            )

    downloaded_bytes = 0

    with destination_path.open(
        "wb"
    ) as archive_file:

        for chunk in response.iter_content(
            chunk_size=8192
        ):

            if not chunk:
                continue

            downloaded_bytes += (
                len(chunk)
            )

            if (
                downloaded_bytes
                > MAX_GITHUB_ARCHIVE_BYTES
            ):
                raise ValueError(
                    "GitHub repository is too "
                    "large to assess."
                )

            archive_file.write(
                chunk
            )
# =====================================================================
# SUBMISSION PROCESSOR
# =====================================================================

def process_submission(
    file_path: str | Path,
    student_note: str | None = None,
) -> dict[str, Any]:

    path = Path(
        file_path
    )

    if not path.exists():
        raise ValueError(
            "Submission file does not exist."
        )

    if not path.is_file():
        raise ValueError(
            "Submission path must point "
            "to a file."
        )

    extension = (
        path.suffix.lower()
    )

    if (
        extension
        not in ALLOWED_SUBMISSION_EXTENSIONS
    ):
        raise ValueError(
            "Unsupported submission "
            f"file type: {extension}"
        )

    result: dict[str, Any] = {
        "submission_file_name": (
            path.name
        ),
        "submission_extension": (
            extension
        ),
        "text_files": [],
        "visual_files": [],
        "student_note": (
            student_note.strip()
            if isinstance(
                student_note,
                str,
            )
            and student_note.strip()
            else None
        ),
        "processing_notes": [],
    }

    total_text_chars = 0

    if extension == ".zip":

        try:

            with zipfile.ZipFile(
                path,
                "r",
            ) as archive:

                for member in archive.infolist():

                    if member.is_dir():
                        continue

                    member_name = (
                        member.filename
                    )

                    if should_skip_archive_member(
                        member_name
                    ):
                        continue

                    member_extension = (
                        Path(
                            member_name
                        ).suffix.lower()
                    )

                    member_size_limit = (
                        MAX_ARCHIVE_MEMBER_BYTES
                    )

                    if (
                        member_extension
                        == ".ipynb"
                    ):
                        member_size_limit = (
                            MAX_NOTEBOOK_BYTES
                        )

                    elif (
                        member_extension
                        == ".csv"
                    ):
                        member_size_limit = (
                            MAX_CSV_BYTES
                        )

                    elif (
                        member_extension
                        in ZIP_VISUAL_EXTENSIONS
                    ):
                        member_size_limit = (
                            MAX_VISUAL_BYTES
                        )

                    if (
                        member.file_size
                        > member_size_limit
                    ):
                        result[
                            "processing_notes"
                        ].append(
                            "Skipped oversized file: "
                            f"{member_name}"
                        )

                        continue

                    if (
                        member_extension
                        in ZIP_VISUAL_EXTENSIONS
                    ):

                        visual_bytes = (
                            archive.read(
                                member
                            )
                        )

                        result[
                            "visual_files"
                        ].append(
                            {
                                "file_name": (
                                    member_name
                                ),
                                "source": (
                                    "zip_member"
                                ),
                                "media_type": (
                                    VISUAL_MEDIA_TYPES[
                                        member_extension
                                    ]
                                ),
                                "content_bytes": (
                                    visual_bytes
                                ),
                            }
                        )

                        continue

                    if (
                        member_extension
                        == ".ipynb"
                    ):

                        notebook_bytes = (
                            archive.read(
                                member
                            )
                        )

                        try:
                            notebook_evidence = (
                                extract_notebook_evidence(
                                    notebook_bytes
                                )
                            )

                        except (
                            json.JSONDecodeError,
                            UnicodeDecodeError,
                        ):
                            result[
                                "processing_notes"
                            ].append(
                                "Could not parse "
                                f"notebook: {member_name}"
                            )

                            continue

                        text = (
                            notebook_evidence[
                                "text"
                            ]
                        )

                        for (
                            visual_output
                        ) in notebook_evidence[
                            "visual_outputs"
                        ]:

                            result[
                                "visual_files"
                            ].append(
                                {
                                    "file_name": (
                                        f"{member_name}"
                                        f"::cell_"
                                        f"{visual_output['cell_index']}"
                                        f"_output_"
                                        f"{visual_output['output_index']}"
                                        f"{visual_output['file_extension']}"
                                    ),
                                    "source": (
                                        "notebook_output"
                                    ),
                                    "notebook_file": (
                                        member_name
                                    ),
                                    "cell_index": (
                                        visual_output[
                                            "cell_index"
                                        ]
                                    ),
                                    "output_index": (
                                        visual_output[
                                            "output_index"
                                        ]
                                    ),
                                    "media_type": (
                                        visual_output[
                                            "media_type"
                                        ]
                                    ),
                                    "content_bytes": (
                                        visual_output[
                                            "image_bytes"
                                        ]
                                    ),
                                }
                            )

                    elif (
                        member_extension
                        == ".csv"
                    ):

                        csv_bytes = (
                            archive.read(
                                member
                            )
                        )

                        text = (
                            extract_csv_evidence(
                                csv_bytes,
                                member_name,
                            )
                        )

                    elif (
                        member_extension
                        in ZIP_TEXT_EXTENSIONS
                    ):

                        raw_content = (
                            archive.read(
                                member
                            )
                        )

                        text = (
                            raw_content.decode(
                                "utf-8",
                                errors="replace",
                            )
                        )

                    else:
                        continue

                    remaining_chars = (
                        MAX_TOTAL_TEXT_CHARS
                        - total_text_chars
                    )

                    if remaining_chars <= 0:
                        result[
                            "processing_notes"
                        ].append(
                            "Maximum total text "
                            "evidence size reached."
                        )

                        break

                    evidence, used_chars = (
                        build_text_evidence(
                            member_name,
                            text,
                            remaining_chars,
                        )
                    )

                    if not evidence[
                        "content"
                    ]:
                        continue

                    result[
                        "text_files"
                    ].append(
                        evidence
                    )

                    total_text_chars += (
                        used_chars
                    )

        except zipfile.BadZipFile as exc:
            raise ValueError(
                "Invalid ZIP submission."
            ) from exc

        return result

    if extension == ".ipynb":

        if (
            path.stat().st_size
            > MAX_NOTEBOOK_BYTES
        ):
            raise ValueError(
                "Notebook submission "
                "is too large."
            )

        notebook_bytes = (
            path.read_bytes()
        )

        try:
            notebook_evidence = (
                extract_notebook_evidence(
                    notebook_bytes
                )
            )

        except (
            json.JSONDecodeError,
            UnicodeDecodeError,
        ) as exc:
            raise ValueError(
                "Invalid Jupyter notebook "
                "submission."
            ) from exc

        text = (
            notebook_evidence[
                "text"
            ]
        )

        evidence, _ = (
            build_text_evidence(
                path.name,
                text,
                MAX_TOTAL_TEXT_CHARS,
            )
        )

        if evidence["content"]:
            result[
                "text_files"
            ].append(
                evidence
            )

        for (
            visual_output
        ) in notebook_evidence[
            "visual_outputs"
        ]:

            result[
                "visual_files"
            ].append(
                {
                    "file_name": (
                        f"{path.name}"
                        f"::cell_"
                        f"{visual_output['cell_index']}"
                        f"_output_"
                        f"{visual_output['output_index']}"
                        f"{visual_output['file_extension']}"
                    ),
                    "source": (
                        "notebook_output"
                    ),
                    "notebook_file": (
                        path.name
                    ),
                    "cell_index": (
                        visual_output[
                            "cell_index"
                        ]
                    ),
                    "output_index": (
                        visual_output[
                            "output_index"
                        ]
                    ),
                    "media_type": (
                        visual_output[
                            "media_type"
                        ]
                    ),
                    "content_bytes": (
                        visual_output[
                            "image_bytes"
                        ]
                    ),
                }
            )

        return result

    if extension == ".csv":

        if (
            path.stat().st_size
            > MAX_CSV_BYTES
        ):
            raise ValueError(
                "CSV submission is too large."
            )

        csv_bytes = (
            path.read_bytes()
        )

        text = (
            extract_csv_evidence(
                csv_bytes,
                path.name,
            )
        )

        evidence, _ = (
            build_text_evidence(
                path.name,
                text,
                MAX_TOTAL_TEXT_CHARS,
            )
        )

        if evidence["content"]:
            result[
                "text_files"
            ].append(
                evidence
            )

        return result

    if extension in {
        ".sql",
        ".py",
        ".txt",
        ".md",
    }:

        text = path.read_text(
            encoding="utf-8",
            errors="replace",
        )

        evidence, _ = (
            build_text_evidence(
                path.name,
                text,
                MAX_TOTAL_TEXT_CHARS,
            )
        )

        if evidence["content"]:
            result[
                "text_files"
            ].append(
                evidence
            )

        return result

    result[
        "visual_files"
    ].append(
        {
            "file_name": (
                path.name
            ),
            "source": (
                "direct_upload"
            ),
            "media_type": (
                VISUAL_MEDIA_TYPES[
                    extension
                ]
            ),
            "file_path": str(
                path
            ),
        }
    )

    return result

# =====================================================================
# PUBLIC GITHUB REPOSITORY SUBMISSION
# =====================================================================

def process_github_submission(
    repository_url: str,
    student_note: str | None = None,
) -> dict[str, Any]:

    owner, repository = (
        parse_github_repository_url(
            repository_url
        )
    )

    repository_data = (
        fetch_github_repository_metadata(
            owner,
            repository,
        )
    )

    default_branch = (
        repository_data[
            "default_branch"
        ]
    )

    with tempfile.TemporaryDirectory() as temp_dir:

        archive_path = (
            Path(temp_dir)
            / f"{repository}.zip"
        )

        download_github_repository_archive(
            owner=owner,
            repository=repository,
            default_branch=default_branch,
            destination_path=archive_path,
        )

        submission_evidence = (
            process_submission(
                archive_path,
                student_note=student_note,
            )
        )

    canonical_repository_url = (
        "https://github.com/"
        f"{owner}/{repository}"
    )

    submission_evidence[
        "submission_file_name"
    ] = (
        f"{repository}.zip"
    )

    submission_evidence[
        "submission_source"
    ] = "github_repository"

    submission_evidence[
        "repository_url"
    ] = canonical_repository_url

    submission_evidence[
        "repository_full_name"
    ] = repository_data.get(
        "full_name",
        f"{owner}/{repository}",
    )

    submission_evidence[
        "repository_default_branch"
    ] = default_branch

    return submission_evidence