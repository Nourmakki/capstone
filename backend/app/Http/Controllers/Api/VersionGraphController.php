<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Edge;
use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Services\NodeChoiceRules;
use App\Services\VersionGraphRules;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

/**
 * Manual authoring of a version's graph: the modules it contains and the
 * prerequisite links between them.
 *
 * Two rules run through everything here.
 *
 * 1. Only a Draft can be edited. A Published version is what students are
 *    following and an Archived one is the record of what they followed;
 *    editing either rewrites history.
 * 2. Node rows are shared between versions, because createVersion clones
 *    version_nodes by node_id. Editing a node that another version also
 *    uses therefore copies it first - see copyOnWrite().
 */
class VersionGraphController extends Controller
{
    private const DIFFICULTIES = ['beginner', 'intermediate', 'advanced'];

    public function __construct(
        private VersionGraphRules $rules,
        private NodeChoiceRules $choiceRules
    )
    {
    }

    private function assertDraft(RoadmapVersion $version): void
    {
        abort_if(
            $version->status !== 'Draft',
            422,
            "Only a Draft version can be edited. This one is {$version->status} - create a new draft version instead."
        );
    }

    /**
     * GET the version's modules with each one's prerequisites resolved,
     * which is the shape the editor screen needs.
     */
    public function index(RoadmapVersion $version)
    {
        return response()->json([
            'version'  => [
                'id'             => $version->id,
                'version_number' => $version->version_number,
                'title'          => $version->title,
                'status'         => $version->status,
                'editable'       => $version->status === 'Draft',
            ],
            'modules' => $this->modulesFor($version),
        ]);
    }

    public function store(Request $request, RoadmapVersion $version)
    {
        $this->assertDraft($version);

        $data = $request->validate([
            'title'                   => ['required', 'string', 'max:255'],
            'description'             => ['required', 'string'],
            'difficulty'              => ['required', Rule::in(self::DIFFICULTIES)],
            'prerequisite_node_ids'   => ['sometimes', 'array'],
            'prerequisite_node_ids.*' => ['integer'],
            // Grouping. Null means ungrouped, which every module is until
            // someone says otherwise - a roadmap with no stages is a flat list.
            'stage'                   => ['nullable', 'integer', 'min:1', 'max:99'],
            'stage_title'             => ['nullable', 'string', 'max:255'],
            'is_recommended'          => ['sometimes', 'boolean'],

            // A choice on an existing module - "HTML" under "Fundamentals" -
            // rather than a step in the rail. Scoped to this version so a
            // payload cannot attach a choice to another roadmap's module.
            'parent_version_node_id'  => [
                'sometimes', 'nullable', 'integer',
                Rule::exists('version_nodes', 'id')->where(
                    fn ($q) => $q->where('roadmap_version_id', $version->id)
                ),
            ],
            // Her separate_roadmap: this opens a roadmap instead of a page.
            'links_to_roadmap_id'     => ['sometimes', 'nullable', 'integer', 'exists:roadmaps,id'],
            'recommendation_reason'   => ['nullable', 'string', 'max:2000'],
        ]);

        return DB::transaction(function () use ($data, $version) {
            $node = Node::create([
                'title'       => $data['title'],
                'description' => $data['description'],
                'difficulty'  => $data['difficulty'],
                'status'      => 'approved',
            ]);

            $parentId = $data['parent_version_node_id'] ?? null;

            $versionNode = VersionNode::create([
                'roadmap_version_id'     => $version->id,
                'node_id'                => $node->id,
                'parent_version_node_id' => $parentId,
                'links_to_roadmap_id'    => $data['links_to_roadmap_id'] ?? null,
                'stage'                  => $data['stage'] ?? null,
                'stage_title'            => $data['stage_title'] ?? null,
                'is_recommended'         => $data['is_recommended'] ?? false,
                'recommendation_reason'  => $data['recommendation_reason'] ?? null,
            ]);

            if (! empty($data['links_to_roadmap_id'])) {
                $this->choiceRules->assertCanLinkToRoadmap(
                    $versionNode->load('roadmapVersion.roadmap'),
                    Roadmap::find($data['links_to_roadmap_id'])
                );
            }

            // A choice is not a step in the sequence, so it takes no edges -
            // its place is decided by the module offering it.
            if ($parentId === null) {
                $this->syncPrerequisites(
                    $version,
                    $node->id,
                    $data['prerequisite_node_ids'] ?? []
                );
            } else {
                $parentNode = VersionNode::with('node')->findOrFail($parentId);

                $this->choiceRules->assertCanHoldChoices($parentNode);
                $this->choiceRules->assertValid($parentNode);
            }

            $this->rules->assertValid($version);

            return response()->json([
                'message' => 'Module added.',
                'modules' => $this->modulesFor($version),
                'added'   => $versionNode->id,
            ], 201);
        });
    }

    public function update(Request $request, VersionNode $versionNode)
    {
        $version = $versionNode->roadmapVersion;
        $this->assertDraft($version);

        $data = $request->validate([
            'title'                   => ['required', 'string', 'max:255'],
            'description'             => ['required', 'string'],
            'difficulty'              => ['required', Rule::in(self::DIFFICULTIES)],
            'prerequisite_node_ids'   => ['sometimes', 'array'],
            'prerequisite_node_ids.*' => ['integer'],
            // Grouping. Null means ungrouped, which every module is until
            // someone says otherwise - a roadmap with no stages is a flat list.
            'stage'                   => ['nullable', 'integer', 'min:1', 'max:99'],
            'stage_title'             => ['nullable', 'string', 'max:255'],
            'is_recommended'          => ['sometimes', 'boolean'],
        ]);

        // Checked against the current id, before copyOnWrite can change it -
        // otherwise a self-reference gets reported as "not part of this version".
        if (in_array($versionNode->node_id, array_map('intval', $data['prerequisite_node_ids'] ?? []), true)) {
            throw ValidationException::withMessages([
                'prerequisite_node_ids' => 'A module cannot be its own prerequisite.',
            ]);
        }

        return DB::transaction(function () use ($data, $version, $versionNode) {
            $nodeId = $this->copyOnWrite($version, $versionNode);

            Node::whereKey($nodeId)->update([
                'title'       => $data['title'],
                'description' => $data['description'],
                'difficulty'  => $data['difficulty'],
            ]);

            // On the version_node, not the Node: a Node is shared across
            // versions and roadmaps, but a module's place in a sequence
            // belongs to the version it sits in.
            $versionNode->update([
                'stage'          => $data['stage'] ?? null,
                'stage_title'    => $data['stage_title'] ?? null,
                'is_recommended' => $data['is_recommended'] ?? false,
            ]);

            if (array_key_exists('prerequisite_node_ids', $data)) {
                $this->syncPrerequisites($version, $nodeId, $data['prerequisite_node_ids']);
            }

            $this->rules->assertValid($version);

            return response()->json([
                'message' => 'Module updated.',
                'modules' => $this->modulesFor($version->fresh()),
            ]);
        });
    }

    public function destroy(VersionNode $versionNode)
    {
        $version = $versionNode->roadmapVersion;
        $this->assertDraft($version);

        // Learning material hangs off version_node_id. Deleting the module
        // would orphan it, so say what is in the way instead of destroying it.
        $attached = [
            'tutorials'          => $versionNode->tutorials()->count(),
            'quizzes'            => $versionNode->quizzes()->count(),
            'projects'           => $versionNode->projects()->count(),
            'external resources' => $versionNode->externalResources()->count(),
        ];

        $blocking = array_filter($attached);

        if ($blocking) {
            $parts = [];
            foreach ($blocking as $label => $count) {
                $parts[] = "{$count} {$label}";
            }

            return response()->json([
                'message' => 'Remove the attached material first: ' . implode(', ', $parts) . '.',
            ], 422);
        }

        return DB::transaction(function () use ($version, $versionNode) {
            $nodeId = $versionNode->node_id;

            $this->detachEdgesTouching($version, $nodeId);
            $versionNode->delete();

            // Drop the underlying node only when nothing else points at it.
            if (! VersionNode::where('node_id', $nodeId)->exists()) {
                Node::whereKey($nodeId)->delete();
            }

            $this->rules->assertValid($version->fresh());

            return response()->json([
                'message' => 'Module removed.',
                'modules' => $this->modulesFor($version->fresh()),
            ]);
        });
    }

    // -----------------------------------------------------------------
    // Internals
    // -----------------------------------------------------------------

    private function modulesFor(RoadmapVersion $version)
    {
        $edges = $this->edgeRowsFor($version);

        // edge from -> to means "from is a prerequisite of to"
        $prerequisites = [];
        foreach ($edges as $edge) {
            $prerequisites[$edge->to_node_id][] = $edge->from_node_id;
        }

        // Rail modules only, each carrying its own choices. A choice is not a
        // step in the sequence, so listing it alongside one would put "HTML"
        // beside "Fundamentals" as though they were the same kind of thing.
        return VersionNode::with(['node', 'choices.node', 'choices.linksToRoadmap', 'linksToRoadmap'])
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->get()
            ->map(fn ($vn) => $this->modulePayload($vn, $prerequisites))
            ->values();
    }

    /** One module, with whatever hangs off it. */
    private function modulePayload(VersionNode $vn, array $prerequisites, bool $withChoices = true): array
    {
        $payload = [
            'version_node_id'       => $vn->id,
            'node_id'               => $vn->node_id,
            'title'                 => $vn->node->title,
            'description'           => $vn->node->description,
            'difficulty'            => $vn->node->difficulty,
            'stage'                 => $vn->stage,
            'stage_title'           => $vn->stage_title,
            'is_recommended'        => $vn->is_recommended,
            'coming_soon'           => $vn->coming_soon,
            'recommendation_reason' => $vn->recommendation_reason,
            'prerequisite_node_ids' => $prerequisites[$vn->node_id] ?? [],
            'material_counts'       => [
                'tutorials' => $vn->tutorials()->count(),
                'quizzes'   => $vn->quizzes()->count(),
                'projects'  => $vn->projects()->count(),
            ],

            // What clicking it does: open a roadmap, offer choices, or show a
            // page of its own.
            'kind'                  => $vn->kind(),
            'links_to_roadmap'      => $vn->linksToRoadmap
                ? ['id' => $vn->linksToRoadmap->id, 'title' => $vn->linksToRoadmap->title]
                : null,
        ];

        if ($withChoices) {
            // Recommended first: it is the answer for a student who does not
            // know which to pick, so it should not need hunting for.
            $payload['choices'] = $vn->choices
                ->sortByDesc('is_recommended')
                ->map(fn ($choice) => $this->modulePayload($choice, $prerequisites, withChoices: false))
                ->values();
        }

        return $payload;
    }

    /** The Edge rows this version actually uses. */
    private function edgeRowsFor(RoadmapVersion $version)
    {
        return VersionEdge::with('edge')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->pluck('edge')
            ->filter()
            ->values();
    }

    /**
     * A node shared with another version is duplicated before being edited,
     * and this version's edges are repointed onto the copy. Without this,
     * renaming a module in a draft would rename it in the published version
     * students are already working through.
     */
    private function copyOnWrite(RoadmapVersion $version, VersionNode $versionNode): int
    {
        $sharedWithOtherVersion = VersionNode::where('node_id', $versionNode->node_id)
            ->where('id', '!=', $versionNode->id)
            ->exists();

        if (! $sharedWithOtherVersion) {
            return $versionNode->node_id;
        }

        $original = Node::findOrFail($versionNode->node_id);

        $copy = Node::create([
            'title'       => $original->title,
            'description' => $original->description,
            'difficulty'  => $original->difficulty,
            'status'      => $original->status,
        ]);

        $oldNodeId = $versionNode->node_id;
        $versionNode->update(['node_id' => $copy->id]);

        // Rebuild this version's edges around the copy, leaving other
        // versions' edges pointing at the original.
        foreach ($this->edgeRowsFor($version) as $edge) {
            if ($edge->from_node_id !== $oldNodeId && $edge->to_node_id !== $oldNodeId) {
                continue;
            }

            $from = $edge->from_node_id === $oldNodeId ? $copy->id : $edge->from_node_id;
            $to   = $edge->to_node_id === $oldNodeId ? $copy->id : $edge->to_node_id;

            VersionEdge::where('roadmap_version_id', $version->id)
                ->where('edge_id', $edge->id)
                ->delete();

            $this->attachEdge($version, $from, $to);
        }

        return $copy->id;
    }

    /**
     * Replace the prerequisites of one node. Every id must be another module
     * in this same version, and the result must stay acyclic - a cycle would
     * lock every module in it forever, since none could ever be reached.
     */
    private function syncPrerequisites(RoadmapVersion $version, int $nodeId, array $prerequisiteNodeIds): void
    {
        $prerequisiteNodeIds = array_values(array_unique(array_map('intval', $prerequisiteNodeIds)));

        $inVersion = VersionNode::where('roadmap_version_id', $version->id)
            ->pluck('node_id')
            ->all();

        foreach ($prerequisiteNodeIds as $id) {
            if ($id === $nodeId) {
                throw ValidationException::withMessages([
                    'prerequisite_node_ids' => 'A module cannot be its own prerequisite.',
                ]);
            }

            if (! in_array($id, $inVersion, true)) {
                throw ValidationException::withMessages([
                    'prerequisite_node_ids' => "Module {$id} is not part of this version.",
                ]);
            }
        }

        // Clear this node's incoming edges in this version, then re-add.
        foreach ($this->edgeRowsFor($version) as $edge) {
            if ($edge->to_node_id === $nodeId) {
                VersionEdge::where('roadmap_version_id', $version->id)
                    ->where('edge_id', $edge->id)
                    ->delete();
            }
        }

        foreach ($prerequisiteNodeIds as $prerequisiteId) {
            $this->attachEdge($version, $prerequisiteId, $nodeId);
        }
    }

    /**
     * Edge rows are global and reused across versions, so look one up before
     * creating it; only the version_edges link is per version.
     */
    private function attachEdge(RoadmapVersion $version, int $fromNodeId, int $toNodeId): void
    {
        $edge = Edge::firstOrCreate([
            'from_node_id' => $fromNodeId,
            'to_node_id'   => $toNodeId,
        ]);

        VersionEdge::firstOrCreate([
            'roadmap_version_id' => $version->id,
            'edge_id'            => $edge->id,
        ]);
    }

    private function detachEdgesTouching(RoadmapVersion $version, int $nodeId): void
    {
        foreach ($this->edgeRowsFor($version) as $edge) {
            if ($edge->from_node_id === $nodeId || $edge->to_node_id === $nodeId) {
                VersionEdge::where('roadmap_version_id', $version->id)
                    ->where('edge_id', $edge->id)
                    ->delete();
            }
        }
    }

}
