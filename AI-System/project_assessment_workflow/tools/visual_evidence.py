import base64
import io
from pathlib import Path
from typing import Any
import pymupdf
from PIL import Image

MAX_VISUAL_DIMENSION = 1600
MAX_PDF_PAGES = 10
JPEG_QUALITY = 85


def normalize_image_bytes(
    image_bytes: bytes,
) -> bytes:

    image = Image.open(
        io.BytesIO(
            image_bytes
        )
    )

    image = image.convert(
        "RGB"
    )

    image.thumbnail(
        (
            MAX_VISUAL_DIMENSION,
            MAX_VISUAL_DIMENSION,
        )
    )

    output_buffer = io.BytesIO()

    image.save(
        output_buffer,
        format="JPEG",
        quality=JPEG_QUALITY,
    )

    return output_buffer.getvalue()


def render_pdf_pages(
    pdf_bytes: bytes,
) -> list[dict[str, Any]]:

    document = pymupdf.open(
        stream=pdf_bytes,
        filetype="pdf",
    )

    rendered_pages: list[
        dict[str, Any]
    ] = []

    page_count = min(
        len(document),
        MAX_PDF_PAGES,
    )

    for page_index in range(
        page_count
    ):

        page = document.load_page(
            page_index
        )

        pixmap = page.get_pixmap(
            matrix=pymupdf.Matrix(
                2,
                2,
            ),
            alpha=False,
        )

        png_bytes = (
            pixmap.tobytes(
                "png"
            )
        )

        normalized_bytes = (
            normalize_image_bytes(
                png_bytes
            )
        )

        rendered_pages.append(
            {
                "page_number": (
                    page_index + 1
                ),
                "media_type": (
                    "image/jpeg"
                ),
                "image_bytes": (
                    normalized_bytes
                ),
            }
        )

    document.close()

    return rendered_pages


def load_visual_bytes(
    visual_file: dict[str, Any],
) -> bytes:

    content_bytes = visual_file.get(
        "content_bytes"
    )

    if isinstance(
        content_bytes,
        bytes,
    ):
        return content_bytes

    file_path = visual_file.get(
        "file_path"
    )

    if not file_path:
        raise ValueError(
            "Visual evidence has neither "
            "content_bytes nor file_path."
        )

    path = Path(
        file_path
    )

    if not path.exists():
        raise ValueError(
            "Visual evidence file "
            "does not exist."
        )

    if not path.is_file():
        raise ValueError(
            "Visual evidence path "
            "must point to a file."
        )

    return path.read_bytes()


def process_visual_evidence(
    visual_files: list[
        dict[str, Any]
    ],
) -> list[dict[str, Any]]:

    processed_visuals: list[
        dict[str, Any]
    ] = []

    for visual_file in visual_files:

        file_name = str(
            visual_file.get(
                "file_name",
                "visual_evidence",
            )
        )

        media_type = str(
            visual_file.get(
                "media_type",
                "",
            )
        )

        try:
            raw_bytes = (
                load_visual_bytes(
                    visual_file
                )
            )

            if (
                media_type
                == "application/pdf"
            ):

                pdf_pages = (
                    render_pdf_pages(
                        raw_bytes
                    )
                )

                for page in pdf_pages:

                    processed_visuals.append(
                        {
                            "file_name": (
                                file_name
                            ),
                            "source": (
                                visual_file.get(
                                    "source"
                                )
                            ),
                            "page_number": (
                                page[
                                    "page_number"
                                ]
                            ),
                            "media_type": (
                                page[
                                    "media_type"
                                ]
                            ),
                            "base64_data": (
                                base64.b64encode(
                                    page[
                                        "image_bytes"
                                    ]
                                ).decode(
                                    "ascii"
                                )
                            ),
                        }
                    )

                continue

            if media_type in {
                "image/png",
                "image/jpeg",
            }:

                normalized_bytes = (
                    normalize_image_bytes(
                        raw_bytes
                    )
                )

                processed_visuals.append(
                    {
                        "file_name": (
                            file_name
                        ),
                        "source": (
                            visual_file.get(
                                "source"
                            )
                        ),
                        "page_number": None,
                        "media_type": (
                            "image/jpeg"
                        ),
                        "base64_data": (
                            base64.b64encode(
                                normalized_bytes
                            ).decode(
                                "ascii"
                            )
                        ),
                    }
                )

        except (
            OSError,
            ValueError,
            RuntimeError,
        ):
            continue

    return processed_visuals