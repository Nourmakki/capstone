"""STEP 5: Build the index.

Run this to (re)build the assistant's knowledge of the platform:

    .venv\\Scripts\\python.exe index_5.py

It replaces the store rather than adding to it. The catalogue changes - a
module is renamed, a roadmap is published, material is approved - and an index
that only ever grew would keep answering from content that no longer exists.
Chunk ids are derived from the passage they came from, so a rebuild overwrites
in place and anything genuinely deleted is cleared first.

Nothing here is scheduled. The index is rebuilt when someone decides the
catalogue has moved on, which for a platform whose content changes a few times
a week is a command, not a cron job.
"""

from chunking_2 import chunk_passages
from embeddings_3 import embed_documents
from loading_1 import load_corpus
from vector_db_4 import clear_collection, get_db_collection


def build_index() -> int:
    passages = load_corpus()
    chunks = chunk_passages(passages)

    texts = [c["text"] for c in chunks]
    vectors = embed_documents(texts)

    print("\n" + "=" * 25)
    print("STEP 4: Storing")
    print("=" * 25)

    removed = clear_collection()

    if removed:
        print(f"Cleared {removed} chunk(s) from the previous index")

    collection = get_db_collection()

    collection.upsert(
        ids=[c["id"] for c in chunks],
        embeddings=vectors,
        documents=texts,
        # Chroma metadata takes scalars only, so anything else is dropped
        # rather than crashing the whole build over one odd field.
        metadatas=[
            {k: v for k, v in c["metadata"].items() if isinstance(v, (str, int, float, bool))}
            for c in chunks
        ],
    )

    total = collection.count()

    print(f"Stored {len(chunks)} chunks. The collection now holds {total}.")

    return total


if __name__ == "__main__":
    count = build_index()

    print("\n" + "=" * 25)
    print(f"Index ready: {count} chunks")
    print("=" * 25)
