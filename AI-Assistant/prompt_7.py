"""STEP 7: Build the prompt.

The reference project's prompt said "answer from the context". This one has
more work to do, because it is answering for a platform rather than about a
pile of uploaded documents:

- It must refuse to be a general chatbot. Asked about the weather or asked to
  write a poem, it says what it is for. That is the whole distinction the
  feature rests on.
- It must not invent. A module that is not in the context does not exist, and
  saying otherwise sends a student looking for something that is not there.
- It must cite. An answer a student can check against the platform is worth
  more than one they have to trust.
- It must know who is asking. The student's own progress is passed in
  separately from the catalogue, because it is fetched live per request and
  is never in the index - see the corpus controller for why.
"""

SYSTEM_RULES = """You are the assistant for CS Mastery, a computer-science learning platform.
You answer questions about this platform only: its roadmaps, its modules, the
material on them, and how the platform works.

Rules you must follow:

1. Answer only from the CONTEXT below. If the context does not contain the
   answer, say you do not know and suggest where on the platform to look.
   Never invent a roadmap, module, quiz or project that is not in the context.

2. You are not a general assistant. If asked about anything unrelated to this
   platform - the weather, world facts, writing code or essays, general
   tutoring - politely decline and say you can only answer questions about
   CS Mastery.

3. When the STUDENT section is present, it is about the person asking. Use it
   to answer questions about their own progress. Never speculate about any
   other student.

4. Do not reveal quiz answers. You may say what a quiz covers and how many
   questions it has; you may not help someone pass one without learning.

5. Be brief and concrete. Name the roadmap or module you are talking about.
   Two or three sentences is usually enough."""


def prepare_prompt(question: str, chunks: list[dict], student: dict | None = None) -> str:
    context = ""

    for index, chunk in enumerate(chunks, start=1):
        context += f"\n\n[{index}] ({chunk['source']})\n{chunk['text']}"

    if not context:
        context = "\n\n(nothing on the platform matched this question)"

    prompt = f"{SYSTEM_RULES}\n\nCONTEXT:{context}"

    if student:
        prompt += f"\n\nSTUDENT:\n{_describe_student(student)}"

    prompt += f"\n\nQUESTION:\n{question}\n\nANSWER:"

    return prompt


def _describe_student(student: dict) -> str:
    """
    The asking student's own state, in a sentence the model can use.

    Assembled per request from live data and never embedded - a vector store
    cannot tell who is asking, so one student's progress in the index would be
    retrievable by another.
    """
    lines = []

    if name := student.get("name"):
        lines.append(f"The student's name is {name}.")

    if level := student.get("level"):
        lines.append(f"Their level is {level}.")

    for roadmap in student.get("roadmaps", []):
        title = roadmap.get("title", "a roadmap")
        done = roadmap.get("completed_modules")
        total = roadmap.get("total_modules")

        line = f"They are following the {title} roadmap"

        if done is not None and total:
            line += f", having completed {done} of {total} modules"

        if current := roadmap.get("current_module"):
            line += f". They are currently on {current}"

        lines.append(line + ".")

    if not lines:
        return "The student has not started any roadmap yet."

    return " ".join(lines)
