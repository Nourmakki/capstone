<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreRoadmapRequest;
use App\Http\Resources\RoadmapResource;
use App\Http\Resources\RoadmapVersionResource;
use App\Models\ExternalResources;
use App\Models\Node;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\UserRoadmapProgress;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Models\Workshop;
use App\Services\VersionPublishRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class RoadmapController extends Controller
{
    /**
     * Material managers see every status, including unapproved. Students
     * only ever see approved material.
     */
    /**
     * The landing page calls index() with no user at all, so this cannot
     * assume one. A guest sees exactly what a student sees: approved roadmaps
     * with a published version.
     */
    private function seesUnapproved(Request $request): bool
    {
        return $request->user()?->role === 'MaterialManager';
    }

    /**
     * Who sees the tree rather than just the top of it.
     *
     * A student picks a subject and is walked down through it, so the list
     * they get is deliberately the top level only. A professor marks work
     * against modules that live three levels down - React's, say - so a list
     * stopping at "Web Development" leaves them nothing to open. Same for a
     * manager, who authors the material down there.
     *
     * It widens what is listed, not what may be seen: a professor still gets
     * only approved, openable roadmaps.
     */
    private function seesWholeTree(Request $request): bool
    {
        return in_array($request->user()?->role, ['MaterialManager', 'Professor'], true);
    }

    public function index(Request $request)
    {
        $staff = $this->seesUnapproved($request);

        // A student sees only the top of the tree - a specialization is reached
        // by choosing it from its parent, not by sitting beside it in the same
        // list. A manager sees every roadmap flat, because the content they
        // author lives on the children, and a picker that hid them would put
        // the material out of reach.
        $query = Roadmap::query()
            // What may be seen at all.
            ->when(! $staff, fn ($q) => $q->approved()->openable())
            // How much of the tree is listed.
            ->when(! $this->seesWholeTree($request), fn ($q) => $q->topLevel());

        $published = fn ($q) => $q->where('status', 'Published')
            ->when(! $staff, fn ($inner) => $inner->approved())
            ->orderByDesc('id');

        $roadmaps = $query->with([
            'versions' => $published,
            'versions.versionNodes',
            // A manager sees this list flat, so a topic needs to say which
            // roadmap it belongs to - two roadmaps may both have a
            // "Foundations". Always null for a student, who only gets the
            // top level.
            'parent' => fn ($q) => $q->when(! $staff, fn ($inner) => $inner->approved()),
            'children' => fn ($q) => $q->when(! $staff, fn ($inner) => $inner->approved())->orderBy('title'),
            'children.versions' => $published,
            'children.versions.versionNodes',
        ])->orderBy('title')->get();

        return RoadmapResource::collection($roadmaps);
    }

    public function show(Request $request, Roadmap $roadmap)
    {
        $staff = $this->seesUnapproved($request);

        // Hidden means hidden: a roadmap kept off the list because it cannot
        // be opened should not be reachable by typing its URL either.
        abort_if(! $staff && (! $roadmap->isApproved() || ! $roadmap->isOpenable()), 404);

        $published = fn ($q) => $q->where('status', 'Published')
            ->when(! $staff, fn ($inner) => $inner->approved())
            ->orderByDesc('id');

        // Children too: a parent roadmap has no modules of its own, so the
        // page shows its specializations instead of an empty rail.
        $roadmap->load([
            'versions' => $published,
            'versions.versionNodes',
            // So a specialization can offer a way back to the roadmap it
            // belongs to. Scoped, or a student would be pointed at a parent
            // they are not allowed to open.
            'parent' => fn ($q) => $q->when(! $staff, fn ($inner) => $inner->approved()),
            'children' => fn ($q) => $q->when(! $staff, fn ($inner) => $inner->approved())->orderBy('title'),
            'children.versions' => $published,
            'children.versions.versionNodes',
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
        // Rail modules only. A choice belongs to the module offering it, and
        // travels nested inside it rather than as a step of its own.
        return VersionNode::with(['node', 'choices.node', 'choices.linksToRoadmap', 'linksToRoadmap'])
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->get()
            ->map(fn ($vn) => $this->nodePayload($vn));
    }

    /** One module as a student sees it, with its choices. */
    private function nodePayload(VersionNode $vn, bool $withChoices = true): array
    {
        $payload = [
            'version_node_id' => $vn->id,
            'node_id'         => $vn->node_id,
            'title'           => $vn->node->title,
            'description'     => $vn->node->description,
            'difficulty'      => $vn->node->difficulty,
            // Null on every module until someone groups them, so an
            // ungrouped roadmap still renders as a flat list.
            'stage'           => $vn->stage,
            'stage_title'     => $vn->stage_title,
            'is_recommended'  => $vn->is_recommended,
            // Announced, its material still being written: shown on the
            // rail but not yet enterable.
            'coming_soon'     => $vn->coming_soon,
            'recommendation_reason' => $vn->recommendation_reason,

            // standard: its own page. choice: buttons, each with a page.
            // roadmap: hands off to a roadmap of its own.
            'kind'            => $vn->kind(),
            'links_to_roadmap' => $vn->linksToRoadmap
                ? ['id' => $vn->linksToRoadmap->id, 'title' => $vn->linksToRoadmap->title]
                : null,
        ];

        if ($withChoices) {
            $payload['choices'] = $vn->choices
                ->sortByDesc('is_recommended')
                ->map(fn ($choice) => $this->nodePayload($choice, withChoices: false))
                ->values();
        }

        return $payload;
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

        // All of it or none of it: a half-cloned version would look complete
        // while quietly missing modules or their material.
        $version = DB::transaction(function () use ($data, $roadmap, $latest) {
            $version = RoadmapVersion::create([
                'roadmap_id'     => $roadmap->id,
                'version_number' => $data['version_number']
                    ?? (string) ($roadmap->versions()->count() + 1),
                'title'          => $data['title'] ?? null,
                'status'         => 'Draft',
            ]);

            if ($latest) {
                app(\App\Services\VersionCloner::class)->copy($latest, $version);
            }

            return $version;
        });

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

        // And every module has to have something in all three tabs.
        (new VersionPublishRules)->assertReadyToPublish($version);

        RoadmapVersion::where('roadmap_id', $version->roadmap_id)
            ->where('status', 'Published')
            ->update(['status' => 'Archived']);

        $version->update(['status' => 'Published']);

        return response()->json([
            'message' => 'Version published.',
            'version' => new RoadmapVersionResource($version),
        ]);
    }

    /**
     * Delete a draft version outright.
     *
     * The foreign keys cascade from roadmap_versions down through version_nodes
     * to tutorials, quizzes, questions, projects and resources, so the delete
     * itself is one line. The guards are the point:
     *
     * - Only a Draft. Published is what students are following, Archived is the
     *   record of what they followed.
     * - user_roadmap_progress cascades too, so refuse if any student has
     *   progress on it rather than silently erasing their history.
     * - workshops cascade as well, so say what would go instead of deleting
     *   scheduled events by surprise.
     */
    public function destroyVersion(RoadmapVersion $version)
    {
        if ($version->status !== 'Draft') {
            return response()->json([
                'message' => "Only a Draft version can be deleted. This one is {$version->status}.",
            ], 422);
        }

        $studentsOnIt = UserRoadmapProgress::where('roadmap_version_id', $version->id)->count();

        if ($studentsOnIt > 0) {
            return response()->json([
                'message' => "{$studentsOnIt} student(s) have progress on this version. Deleting it would erase their history.",
            ], 422);
        }

        $workshops = Workshop::where('roadmap_version_id', $version->id)->count();

        if ($workshops > 0) {
            return response()->json([
                'message' => "{$workshops} workshop(s) are scheduled against this version. Move or delete them first.",
            ], 422);
        }

        $nodeIds = VersionNode::where('roadmap_version_id', $version->id)->pluck('id');
        $nodeRowIds = VersionNode::where('roadmap_version_id', $version->id)->pluck('node_id');

        $removed = [
            'modules'   => $nodeIds->count(),
            'tutorials' => Tutorial::whereIn('version_node_id', $nodeIds)->count(),
            'quizzes'   => Quiz::whereIn('version_node_id', $nodeIds)->count(),
            'projects'  => Project::whereIn('version_node_id', $nodeIds)->count(),
            'resources' => ExternalResources::whereIn('version_node_id', $nodeIds)->count(),
        ];

        DB::transaction(function () use ($version, $nodeRowIds) {
            $version->delete();

            // version_nodes cascade away, but the nodes they pointed at do not.
            // Drop the ones no other version still uses.
            Node::whereIn('id', $nodeRowIds)
                ->whereNotIn('id', VersionNode::pluck('node_id'))
                ->delete();
        });

        return response()->json([
            'message' => 'Draft version deleted.',
            'removed' => $removed,
        ]);
    }
}