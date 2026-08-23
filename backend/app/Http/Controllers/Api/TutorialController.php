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

    public function update(Request $request, $id)
    {
        $tutorial = Tutorial::find($id);

        if (! $tutorial) {
            return response()->json(['message' => 'Tutorial not found'], 404);
        }

        $data = $request->validate($this->rules());

        $tutorial->update($data);

        // Edited content has to be reviewed again.
        if ($tutorial->wasChanged() && $tutorial->review_status === 'approved') {
            $tutorial->update([
                'review_status' => 'pending',
                'reviewed_by'   => null,
                'reviewed_at'   => null,
                'review_notes'  => null,
            ]);
        }

        return response()->json([
            'message'  => 'Tutorial updated successfully',
            'tutorial' => $this->payload($tutorial->fresh()),
        ]);
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
