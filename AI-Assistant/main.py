"""STEP 9: The assistant as a service.

    .venv\\Scripts\\python.exe -m uvicorn main:app --port 8002

Port 8002, beside AI-System on 8001 and Laravel on 8000. A separate process
with its own environment and its own key, so neither service can break the
other by being restarted, reinstalled or rate-limited.

Laravel calls this; the browser never does. That matters for the student
section of the payload: the platform decides who is asking and what their
progress is, and passes it in. If the browser could set that field, a student
could ask about somebody else's progress by editing a request.
"""

from fastapi import FastAPI
from pydantic import BaseModel, Field

from call_llm_8 import generate_answer
from prompt_7 import prepare_prompt
from similarity_6 import retrieve_relevant_chunks
from vector_db_4 import get_db_collection

app = FastAPI(title="CS Mastery Assistant")


class StudentRoadmap(BaseModel):
    title: str
    completed_modules: int | None = None
    total_modules: int | None = None
    current_module: str | None = None


class Student(BaseModel):
    """
    Who is asking, as the platform sees them.

    Sent by Laravel per request and never stored here - none of it is in the
    index, because a vector store cannot tell one student's question from
    another's.
    """

    name: str | None = None
    level: str | None = None
    roadmaps: list[StudentRoadmap] = Field(default_factory=list)


class AskRequest(BaseModel):
    question: str = Field(min_length=2, max_length=1000)
    student: Student | None = None


class Source(BaseModel):
    title: str
    kind: str
    roadmap: str | None = None
    version_node_id: int | None = None


class AskResponse(BaseModel):
    answer: str
    sources: list[Source]
    # False when nothing in the catalogue was close enough to the question.
    # The interface can then say so plainly instead of presenting a refusal
    # as though it were an answer.
    grounded: bool


@app.post("/reindex")
def reindex() -> dict:
    """
    Rebuild the index from the platform's current catalogue.

    Called by Laravel when content is published or approved, so the assistant
    stops being a snapshot of whenever someone last remembered to run the
    indexer by hand. Cheap enough to do on every change: embedding runs
    locally, so a rebuild costs a few seconds and no quota.

    Imported here rather than at module load because the indexer reaches out
    to Laravel, and the service must be able to start when Laravel is not up.
    """
    from index_5 import build_index

    return {"status": "ok", "chunks": build_index()}


@app.get("/health")
def health() -> dict:
    """Whether the assistant can actually answer: an empty index cannot."""
    count = get_db_collection().count()

    return {
        "status": "ok" if count else "no index",
        "chunks": count,
        "detail": None if count else "Run index_5.py to build the index.",
    }


def _retrieval_query(question: str, student: Student | None) -> str:
    """
    The question, plus what the student is actually working on.

    "What should I learn next?" contains no roadmap and no module, so it
    retrieves whatever is vaguely about learning - and the answer comes back
    saying it does not have the details of the module the student is on, while
    the platform knew all along. Naming their current roadmap and module in
    the search text puts those passages in front of the model.

    Only the titles are added. Nothing here is embedded or stored; it shapes
    one query and is gone.
    """
    if not student or not student.roadmaps:
        return question

    hints = []

    for roadmap in student.roadmaps[:2]:
        hints.append(roadmap.title)

        if roadmap.current_module:
            hints.append(roadmap.current_module)

    return f"{question} ({', '.join(hints)})"


@app.post("/ask", response_model=AskResponse)
def ask(request: AskRequest) -> AskResponse:
    chunks = retrieve_relevant_chunks(_retrieval_query(request.question, request.student))

    # Answered without calling the model at all. Nothing retrieved means
    # nothing to answer from, and asking anyway invites exactly the invention
    # this design exists to prevent - it would answer from what it happens to
    # know about React rather than from this platform.
    if not chunks:
        return AskResponse(
            answer=(
                "I do not know that one. I can only answer questions about "
                "CS Mastery - its roadmaps, the modules on them, and how the "
                "platform works."
            ),
            sources=[],
            grounded=False,
        )

    prompt = prepare_prompt(
        request.question,
        chunks,
        request.student.model_dump() if request.student else None,
    )

    answer = generate_answer(prompt)

    return AskResponse(
        answer=answer,
        sources=[
            Source(
                title=chunk["metadata"].get("title", "the platform"),
                kind=chunk["metadata"].get("type", "passage"),
                roadmap=chunk["metadata"].get("roadmap"),
                version_node_id=chunk["metadata"].get("version_node_id"),
            )
            for chunk in chunks
        ],
        grounded=True,
    )
