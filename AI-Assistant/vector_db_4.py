"""STEP 4: The vector store.

Chroma, persisted to disk, exactly as the reference project used it. The path
is configurable so the index does not have to live in the working directory,
and it is gitignored: it is derived data, rebuilt by running the indexer.
"""

import os

import chromadb
from dotenv import load_dotenv

load_dotenv()

COLLECTION_NAME = "cs_mastery_catalogue"

_client = None
_collection = None


def get_vector_db_client() -> chromadb.ClientAPI:
    global _client

    if _client is None:
        path = os.getenv("CHROMA_PATH", "./chroma_persist")
        _client = chromadb.PersistentClient(path=path)

    return _client


def get_db_collection(name: str = COLLECTION_NAME):
    global _collection

    if _collection is None:
        client = get_vector_db_client()

        # get_or_create rather than checking the list first: the check and the
        # create are two steps, and the indexer and the API can both reach
        # this on a cold start.
        _collection = client.get_or_create_collection(
            name=name,
            # Our own vectors go in, so Chroma must not try to embed anything
            # itself - its default would silently use a different model from
            # the one the questions are embedded with, and retrieval would
            # return nonsense rather than fail.
            embedding_function=None,
            metadata={"hnsw:space": "cosine"},
        )

    return _collection


def clear_collection() -> int:
    """Empty the store. Returns how many chunks were removed."""
    collection = get_db_collection()
    existing = collection.get()["ids"]

    if existing:
        collection.delete(ids=existing)

    return len(existing)
