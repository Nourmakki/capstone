<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Tutorial;
use Illuminate\Http\Request;

class TutorialController extends Controller
{
    /**
     * Material managers see every status, including unapproved. Students
     * only ever see approved material.
     */
    private function isStaff(Request $request): bool
    {
        return $request->user()->role === 'MaterialManager';
    }

    private function rules(): array
    {
        return [
            'version_node_id' => ['required', 'exists:version_nodes,id'],
            'title'           => ['required', 'string', 'max:255'],
            'url'             => ['required', 'url', 'max:255'],
            'description'     => ['required', 'string'],
            'source_name'     => ['required', 'string', 'max:255'],
        ];
    }

    public function index(Request $request, $versionNodeId)
    {
        $query = Tutorial::where('version_node_id', $versionNodeId);

        if (! $this->isStaff($request)) {
            $query->approved();
        }

        return response()->json(
            $query->orderBy('title')->get()->map(fn ($t) => $this->payload($t))
        );
    }

    public function show(Request $request, $id)
    {
        $tutorial = Tutorial::with('versionNode')->find($id);

        if (! $tutorial) {
            return response()->json(['message' => 'Tutorial not found'], 404);
        }

        if (! $this->isStaff($request) && ! $tutorial->isApproved()) {
            return response()->json(['message' => 'Tutorial not found'], 404);
        }

        return response()->json($this->payload($tutorial));
    }

    public function store(Request $request)
    {
        $data = $request->validate($this->rules());

        // Only validated fields: $request->all() would let a caller set
        // review_status and approve their own tutorial.
        $tutorial = Tutorial::create($data);

        return response()->json([
            'message'  => 'Tutorial created successfully',
            'tutorial' => $this->payload($tutorial->fresh()),
        ], 201);
    }

    /**
     * Ask the AI for a different video for this module.
     *
     * A tutorial is somebody else's video: there is nothing in it a manager
     * can usefully rewrite, so editing was removed and this replaces it. Nour's
     * schema draws the same line - an edit of stage "tutorial" raises
     * "Tutorial cannot be edited. Use regenerate instead."
     *
     * Her regenerate action only exists inside a live workflow thread, and a
     * committed tutorial's thread is finished, so this starts a fresh run for
     * the module. That run covers the quiz, project and resources too - she
     * has no tutorial-only endpoint - and the manager regenerates just the
     * tutorial stage from the review panel before committing.
     */
    public function regenerate(Request $request, Tutorial $tutorial, LearningWorkflowController $workflows)
    {
        $versionNode = $tutorial->versionNode;

        if (! $versionNode) {
            return response()->json([
                'message' => 'This tutorial is not attached to a module, so there is nothing to regenerate from.',
            ], 422);
        }

        $response = $workflows->store($versionNode);

        // 409 means the module already has a run awaiting review - the manager
        // should finish that one rather than start a second.
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        return response()->json([
            'message' => 'Generation started. Review it under Learning Material, '
                . 'where you can regenerate just the tutorial before committing.',
            'run_id'  => $response->getData(true)['run_id'] ?? null,
            // The old tutorial is left alone: committing adds the new one
            // beside it rather than replacing it, so the manager decides which
            // to keep once they have seen both.
            'replaces_tutorial_id' => $tutorial->id,
        ], 201);
    }

    public function destroy($id)
    {
        $tutorial = Tutorial::find($id);

        if (! $tutorial) {
            return response()->json(['message' => 'Tutorial not found'], 404);
        }

        $tutorial->delete();

        return response()->json(['message' => 'Tutorial deleted successfully']);
    }

    private function payload(Tutorial $tutorial): array
    {
        return [
            'id'              => $tutorial->id,
            'version_node_id' => $tutorial->version_node_id,
            'title'           => $tutorial->title,
            'url'             => $tutorial->url,
            'description'     => $tutorial->description,
            'source_name'     => $tutorial->source_name,
            'review_status'   => $tutorial->review_status,
            'review_notes'    => $tutorial->review_notes,
            'created_at'      => $tutorial->created_at,
        ];
    }
}
