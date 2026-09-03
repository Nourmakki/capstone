"""STEP 3: Turn text into vectors.

Runs locally, on all-MiniLM-L6-v2 - the same model the reference project used,
reached through Chroma's bundled ONNX runtime rather than PyTorch. An 80 MB
model file, cached after the first run, and no PyTorch download.

Local rather than Gemini's embedding endpoint, for a reason worth recording:
this project's own Gemini key can list models but is refused by every one of
them with "Your project has been denied access", while the key in AI-System
embeds happily. So it is not the region and not Gemini - it is something about
the Google project behind the new key. Embedding locally sidesteps the
question entirely, costs nothing, and needs no key at all, which also means
the index can be rebuilt offline.

Generation still needs a model, and that is a separate decision made in
call_llm_8.py. Nothing here depends on it.
"""

from chromadb.utils import embedding_functions

MODEL = "all-MiniLM-L6-v2"
DIMENSIONS = 384

_embedder = None


def _as_plain_floats(vectors) -> list[list[float]]:
    """
    numpy float32 out, Python float in.

    The embedder returns numpy arrays, and Chroma's validator refuses them -
    it wants lists of Python floats and says so only at the point of upsert,
    after every vector has already been computed.
    """
    return [[float(x) for x in vector] for vector in vectors]


def get_embedder():
    global _embedder

    if _embedder is None:
        # Downloads the model on first use, then reads it from
        # ~/.cache/chroma on every run after that.
        _embedder = embedding_functions.DefaultEmbeddingFunction()

    return _embedder


def embed_documents(texts: list[str]) -> list[list[float]]:
    """Embed passages for storage."""
    print("\n" + "=" * 25)
    print("STEP 3: Embedding")
    print("=" * 25)
    print(f"Embedding {len(texts)} chunks with {MODEL} ({DIMENSIONS} dimensions), locally")

    vectors = get_embedder()(texts)

    print(f"Done - {len(vectors)} vectors")

    return _as_plain_floats(vectors)


def embed_query(text: str) -> list[list[float]]:
    """
    Embed one question for searching.

    The same model as the documents, which is not a detail: a question
    embedded by a different model lands nowhere near the passages that answer
    it, and the failure is silent - retrieval returns its top matches as
    confidently as ever, and they are simply wrong.
    """
    return _as_plain_floats(get_embedder()([text]))
