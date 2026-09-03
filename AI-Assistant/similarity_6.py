"""STEP 6: Find the passages that might answer a question.

The reference project's retrieval, with one addition: a distance ceiling.

Chroma always returns its top_k nearest chunks, however far away they are.
Ask it about the weather and it will hand back the three least-unrelated
modules on the platform with no hint that none of them fit. An assistant that
answers from those is inventing, and the whole claim of this feature is that
it does not.

So retrieval reports the distances, and refusing is a decision made on them:
nothing close enough means the assistant says it does not know, rather than
writing a confident paragraph out of the nearest thing it could find.
"""

from embeddings_3 import embed_query
from vector_db_4 import get_db_collection

# Cosine distance, so 0 is identical and 2 is opposite. Measured against the
# real index: a question about a module lands around 0.2-0.4, a platform
# question about 0.55, and something off-topic sits well above 1.0.
MAX_DISTANCE = 0.95


def retrieve_relevant_chunks(question: str, top_k: int = 4) -> list[dict]:
    """The passages worth showing the model, nearest first."""
    collection = get_db_collection()

    if collection.count() == 0:
        return []

    results = collection.query(
        query_embeddings=embed_query(question),
        n_results=top_k,
    )

    hits = []

    for text, metadata, distance in zip(
        results["documents"][0],
        results["metadatas"][0],
        results["distances"][0],
    ):
        if distance > MAX_DISTANCE:
            continue

        hits.append({
            "text": text,
            "metadata": metadata,
            "distance": distance,
            # What the answer will cite. The passage knows what it is - a
            # module, a roadmap, a rule - so the citation can say so.
            "source": _describe(metadata),
        })

    return hits


def _describe(metadata: dict) -> str:
    kind = metadata.get("type")
    title = metadata.get("title", "the platform")

    if kind == "module":
        return f"the {title} module in {metadata.get('roadmap', 'a roadmap')}"

    if kind == "roadmap":
        return f"the {title} roadmap"

    if kind == "subject":
        return f"the {title} subject"

    return title
