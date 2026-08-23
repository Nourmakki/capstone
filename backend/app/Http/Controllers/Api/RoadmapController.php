<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoadmapRequest;
use App\Http\Resources\RoadmapResource;
use App\Http\Resources\RoadmapVersionResource;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Http\Request;

class RoadmapController extends Controller
{
    /**
     * Material managers see every status, including unapproved. Students
     * only ever see approved material.
     */
    private function seesUnapproved(Request $request): bool
    {
        return $request->user()->role === 'MaterialManager';
    }

    public function index(Request $request)
    {
        $staff = $this->seesUnapproved($request);

        $query = Roadmap::query()->when(! $staff, fn ($q) => $q->approved());

        $roadmaps = $query->with([
            'versions' => fn ($q) => $q->where('status', 'Published')
                ->when(! $staff, fn ($inner) => $inner->approved())
                ->orderByDesc('id'),
            'versions.versionNodes',
        ])->orderBy('title')->get();

        return RoadmapResource::collection($roadmaps);
    }

    public function show(Request $request, Roadmap $roadmap)
    {
        $staff = $this->seesUnapproved($request);

        abort_if(! $staff && ! $roadmap->isApproved(), 404);

        $roadmap->load([
            'versions' => fn ($q) => $q->where('status', 'Published')
                ->when(! $staff, fn ($inner) => $inner->approved())
                ->orderByDesc('id'),
            'versions.versionNodes',
        ]);

        return new RoadmapResource($roadmap);
    }

    public function versions(Roadmap $roadmap)
    {
        return RoadmapVersionResource::collection(
            $roadmap->versions()->orderByDesc('id')->get()
        );
    }

    public function latestVersion(Roadmap $roadmap)
    {
        // Newest published version, ordered by creation.
        $version = $roadmap->versions()
            ->where('status', 'Published')
            ->orderByDesc('id')
            ->first();

        if (! $version) {
            return response()->json([
                'message' => 'This roadmap has no published version yet.',
            ], 404);
        }

        return new RoadmapVersionResource($version);
    }

    /**
     * Nodes and edges of one version, flattened so the client can draw
     * the graph without extra lookups.
     */
    public function graph(RoadmapVersion $version)
    {
        return response()->json([
            'version' => new RoadmapVersionResource($version),
            'nodes'   => $this->nodesFor($version),
            'edges'   => $this->edgesFor($version),
        ]);
    }

    /**
     * The exact shape the LangGraph AI service expects as its AgentState input.
     */
    public function aiExport(RoadmapVersion $version)
    {
        $version->load('roadmap');

        // Field set must match AI-System/api/schema.py StartWorkflowRequest,
        // which sets extra="forbid" - any additional key is rejected with a 422.
        return response()->json([
            'roadmap_id'     => $version->roadmap_id,
            'roadmap_topic'  => strtolower($version->roadmap->title),
            'version_id'     => $version->id,
            'version_number' => (string) $version->version_number,
            'nodes'          => $this->nodesFor($version)
                ->map(fn ($n) => [
                    'id'          => $n['node_id'],
                    'title'       => $n['title'],
                    'description' => $n['description'],
                ])->values(),
            'edges'          => $this->edgesFor($version)
                ->map(fn ($e) => [
                    'from_node_id' => $e['from_node_id'],
                    'to_node_id'   => $e['to_node_id'],
                ])->values(),
        ]);
    }

    public function store(StoreRoadmapRequest $request)
    {
        $roadmap = Roadmap::create($request->validated());

        return (new RoadmapResource($roadmap))
            ->response()
            ->setStatusCode(201);
    }

    public function update(StoreRoadmapRequest $request, Roadmap $roadmap)
    {
        $roadmap->update($request->validated());

        return new RoadmapResource($roadmap);
    }

    public function destroy(Roadmap $roadmap)
    {
        $roadmap->delete();

        return response()->json(['message' => 'Roadmap deleted.']);
    }

    private function nodesFor(RoadmapVersion $version)
    {
        return VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->map(fn ($vn) => [
                'version_node_id' => $vn->id,
                'node_id'         => $vn->node_id,
                'title'           => $vn->node->title,
                'description'     => $vn->node->description,
                'difficulty'      => $vn->node->difficulty,
            ]);
    }

    private function edgesFor(RoadmapVersion $version)
    {
        // Eager-load the edge so from/to node ids are actually present.
        return VersionEdge::with('edge')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->filter(fn ($ve) => $ve->edge !== null)
            ->map(fn ($ve) => [
                'id'           => $ve->edge->id,
                'from_node_id' => $ve->edge->from_node_id,
                'to_node_id'   => $ve->edge->to_node_id,
            ]);
    }

        /**
     * Start a new draft version by copying the latest one's graph.
     */
    public function createVersion(Request $request, Roadmap $roadmap)
    {
        $data = $request->validate([
            // Labels are free text so "2.1" or "2026 refresh" are valid.
            'version_number' => ['nullable', 'string', 'max:20'],
            'title'          => ['nullable', 'string', 'max:255'],
        ]);

        $latest = $roadmap->versions()->orderByDesc('id')->first();

        $version = RoadmapVersion::create([
            'roadmap_id'     => $roadmap->id,
            'version_number' => $data['version_number']
                ?? (string) ($roadmap->versions()->count() + 1),
            'title'          => $data['title'] ?? null,
            'status'         => 'Draft',
        ]);

        if ($latest) {
            // Clone nodes and edges so the draft starts from the live graph.
            foreach (VersionNode::where('roadmap_version_id', $latest->id)->get() as $node) {
                VersionNode::create([
                    'roadmap_version_id' => $version->id,
                    'node_id'            => $node->node_id,
                ]);
            }

            foreach (VersionEdge::where('roadmap_version_id', $latest->id)->get() as $edge) {
                VersionEdge::create([
                    'roadmap_version_id' => $version->id,
                    'edge_id'            => $edge->edge_id,
                ]);
            }
        }

        return response()->json([
            'message' => 'Draft version created.',
            'version' => new RoadmapVersionResource($version),
        ], 201);
    }

    /**
     * Publish a draft. Older published versions are archived so exactly
     * one version is live at a time.
     */
    public function publishVersion(RoadmapVersion $version)
    {
        if ($version->status === 'Published') {
            return response()->json(['message' => 'This version is already published.'], 409);
        }

        // A material manager gates what goes live.
        if (! $version->isApproved()) {
            return response()->json([
                'message' => 'This version must be approved by a material manager before publishing.',
            ], 409);
        }

        RoadmapVersion::where('roadmap_id', $version->roadmap_id)
            ->where('status', 'Published')
            ->update(['status' => 'Archived']);

        $version->update(['status' => 'Published']);

        return response()->json([
            'message' => 'Version published.',
            'version' => new RoadmapVersionResource($version),
        ]);
    }
}