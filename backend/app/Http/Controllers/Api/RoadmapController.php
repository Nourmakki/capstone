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

class RoadmapController extends Controller
{
    public function index()
    {
        $roadmaps = Roadmap::with([
            'versions' => fn ($q) => $q->where('status', 'Published')
                ->orderByDesc('version_number'),
            'versions.versionNodes',
        ])->orderBy('title')->get();

        return RoadmapResource::collection($roadmaps);
    }

    public function show(Roadmap $roadmap)
    {
        $roadmap->load([
            'versions' => fn ($q) => $q->where('status', 'Published')
                ->orderByDesc('version_number'),
            'versions.versionNodes',
        ]);

        return new RoadmapResource($roadmap);
    }

    public function versions(Roadmap $roadmap)
    {
        return RoadmapVersionResource::collection(
            $roadmap->versions()->orderByDesc('version_number')->get()
        );
    }

    public function latestVersion(Roadmap $roadmap)
    {
        // Ordered by version_number, not created_at.
        $version = $roadmap->versions()
            ->where('status', 'Published')
            ->orderByDesc('version_number')
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

        return response()->json([
            'roadmap_id'     => $version->roadmap_id,
            'roadmap_title'  => $version->roadmap->title,
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
}