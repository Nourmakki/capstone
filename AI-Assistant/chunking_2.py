"""STEP 2: Chunk the passages.

Same sliding window as the reference project, with one difference that matters
here: a passage is only split when it is actually too long.

The reference chunked essays, where a fixed window is the only option. Ours
are already one-thought-each - a module, a roadmap, a rule - and most fit
inside a single chunk. Splitting them anyway would cut "It contains a video
tutorial..." away from the module it belongs to, and retrieval would return a
list of material with nothing saying whose it is.

Every chunk keeps its passage's metadata, so an answer can cite the module or
roadmap it came from rather than a chunk number.
"""

CHUNK_SIZE = 1200
OVERLAP = 150


def chunk_text(text: str, chunk_size: int = CHUNK_SIZE, overlap: int = OVERLAP) -> list[str]:
    if len(text) <= chunk_size:
        return [text.strip()]

    chunks = []
    start = 0

    while start < len(text):
        end = start + chunk_size
        chunks.append(text[start:end].strip())
        start = end - overlap

    return [c for c in chunks if c]


def chunk_passages(
    passages: list[dict],
    chunk_size: int = CHUNK_SIZE,
    overlap: int = OVERLAP,
) -> list[dict]:
    print("\n" + "=" * 25)
    print("STEP 2: Chunking")
    print("=" * 25)
    print(f"Chunk size: {chunk_size} characters, overlap: {overlap}")

    all_chunks = []
    split_count = 0

    for passage in passages:
        pieces = chunk_text(passage["text"], chunk_size, overlap)

        if len(pieces) > 1:
            split_count += 1

        for index, piece in enumerate(pieces):
            all_chunks.append({
                # Stable and readable: re-running the indexer overwrites the
                # same ids rather than growing a second copy of everything.
                "id": passage["id"] if len(pieces) == 1 else f"{passage['id']}-{index}",
                "text": piece,
                "metadata": {
                    **passage["metadata"],
                    "passage_id": passage["id"],
                    "chunk_index": index,
                },
            })

    print(f"{len(passages)} passages -> {len(all_chunks)} chunks")
    print(f"  {split_count} passage(s) were long enough to split")

    return all_chunks
