"""
A stand-in for the LangGraph FastAPI service, for testing the Laravel side
of the AI workflow without Postgres, API keys, or ~90 packages.

Standard library only. Run it with:

    py backend/tools/ai-stub/server.py

It answers on 127.0.0.1:8001, which is what AI_SERVICE_URL already defaults
to, so nothing in .env needs changing.

The scenario it serves is whatever JSON file is currently selected:

    GET  /_use/clean        switch to scenarios/clean.json
    GET  /_scenarios        list what is available

POST /roadmap-workflows returns the scenario name as the thread_id, so the
workflow row Laravel stores points straight back at the same file.
"""

import json
import os
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

HERE = os.path.dirname(os.path.abspath(__file__))
SCENARIO_DIR = os.path.join(HERE, "scenarios")

current = {"name": "clean"}

# roadmap_ai_workflows.thread_id is unique, so every run needs its own id.
# The scenario name stays as the prefix so a thread id is still readable.
counter = {"n": 0}


def scenario_of(thread_id):
    """clean-3 -> clean. Plain 'clean' still works."""
    name = thread_id.rsplit("-", 1)[0] if "-" in thread_id else thread_id
    return name if load(name) is not None else thread_id


def scenarios():
    if not os.path.isdir(SCENARIO_DIR):
        return []
    return sorted(
        f[:-5] for f in os.listdir(SCENARIO_DIR) if f.endswith(".json")
    )


def load(name):
    path = os.path.join(SCENARIO_DIR, name + ".json")
    if not os.path.exists(path):
        return None
    with open(path, encoding="utf-8") as handle:
        return json.load(handle)


class Handler(BaseHTTPRequestHandler):
    # A browser holding a keep-alive connection would otherwise tie up a
    # thread indefinitely, so every response closes its connection.
    protocol_version = "HTTP/1.0"

    def send_json(self, payload, status=200):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(status)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.send_header("Connection", "close")
        self.end_headers()
        self.wfile.write(body)

    def workflow_response(self, thread_id):
        payload = load(scenario_of(thread_id))

        if payload is None:
            return self.send_json(
                {"message": "No scenario for thread '%s'." % thread_id}, 404
            )

        # Shape mirrors api/schema.py WorkflowResponse.
        self.send_json(
            {
                "thread_id": thread_id,
                "status": payload.get("status", "ready_for_commit"),
                "review_stage": payload.get("review_stage"),
                "data": payload.get("data"),
                "rejection_feedback": None,
                "error": None,
            }
        )

    def do_GET(self):
        path = self.path.rstrip("/")

        if path == "/health":
            return self.send_json({"status": "ok", "stub": True})

        if path == "/_scenarios":
            return self.send_json(
                {"current": current["name"], "available": scenarios()}
            )

        if path.startswith("/_use/"):
            name = path.split("/_use/", 1)[1]
            if load(name) is None:
                return self.send_json(
                    {"message": "No scenario named '%s'." % name}, 404
                )
            current["name"] = name
            return self.send_json({"current": name})

        if path.startswith("/roadmap-workflows/"):
            return self.workflow_response(path.rsplit("/", 1)[1])

        self.send_json({"message": "Not found."}, 404)

    def do_POST(self):
        path = self.path.rstrip("/")

        # Laravel starting a run: mint a fresh id under the current scenario.
        if path == "/roadmap-workflows":
            self.drain()
            counter["n"] += 1
            return self.workflow_response(
                "%s-%d" % (current["name"], counter["n"])
            )

        # Reviewer decisions are accepted and ignored - the scenario file is
        # the source of truth for what the proposal looks like.
        if path.startswith("/roadmap-workflows/") and path.endswith("/resume"):
            self.drain()
            name = path.split("/roadmap-workflows/", 1)[1].rsplit("/", 1)[0]
            return self.workflow_response(name)

        self.drain()
        self.send_json({"message": "Not found."}, 404)

    def drain(self):
        length = int(self.headers.get("Content-Length") or 0)
        if length:
            self.rfile.read(length)

    def log_message(self, fmt, *args):
        print("  %s" % (fmt % args))


if __name__ == "__main__":
    print("AI stub on http://127.0.0.1:8001")
    print("scenarios: %s" % ", ".join(scenarios()))
    print("current:   %s" % current["name"])
    print("switch:    http://127.0.0.1:8001/_use/<name>")
    print()
    ThreadingHTTPServer(("127.0.0.1", 8001), Handler).serve_forever()
