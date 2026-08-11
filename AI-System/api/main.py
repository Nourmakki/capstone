from fastapi import FastAPI, HTTPException
from api.schema import (AdminDecisionRequest,StartWorkflowRequest,WorkflowResponse,)
from api.response_mapper import build_workflow_response
from graph.workflow_options import (start_roadmap_workflow,resume_roadmap_workflow,get_roadmap_workflow_state,)
from graph.state import AgentState

app = FastAPI(
    title="AI System API",
    version="1.0.0",
)

@app.get("/health")
def health_check() -> dict[str, str]:
    return {
        "status": "ok"
    }


# @app.post("/test/start-request")
# def test_start_request(
#     request: StartWorkflowRequest,
# ) -> dict:
#     return {
#         "message": "Start request is valid.",
#         "data": request.model_dump(),
#         # model_dump() converts the Pydantic model into a normal Python dictionary.
#     }


# @app.post("/test/admin-decision")
# def test_admin_decision(
#     decision: AdminDecisionRequest,
# ) -> dict:
#     return {
#         "message": "Admin decision is valid.",
#         "data": decision.model_dump(),
#     }
@app.post("/roadmap-workflows",response_model=WorkflowResponse,)
def start_workflow(
    request: StartWorkflowRequest,
) -> WorkflowResponse:

    initial_state: AgentState = request.model_dump()

    workflow_output = start_roadmap_workflow(
        initial_state
    )

    return build_workflow_response(
        thread_id=workflow_output["thread_id"],
        result=workflow_output["result"],
    )

@app.post(
    "/roadmap-workflows/{thread_id}/resume",
    response_model=WorkflowResponse,
)
def resume_workflow(
    thread_id: str,
    decision: AdminDecisionRequest,
) -> WorkflowResponse:

    try:
        result = resume_roadmap_workflow(
            thread_id=thread_id,
            admin_decision=decision.model_dump(
                exclude_none=True
            ),
        )

    except ValueError as exc:
        raise HTTPException(
            status_code=409,
            detail=str(exc),
        ) from exc

    return build_workflow_response(
        thread_id=thread_id,
        result=result,
    )

@app.get("/roadmap-workflows/{thread_id}",response_model=WorkflowResponse,)
def get_workflow(
    thread_id: str,
) -> WorkflowResponse:

    workflow_snapshot = (
        get_roadmap_workflow_state(
            thread_id
        )
    )

    state = workflow_snapshot[
        "state"
    ]

    if not state:
        raise HTTPException(
            status_code=404,
            detail="Workflow not found.",
        )

    return build_workflow_response(
        thread_id=thread_id,
        result=state,
    )