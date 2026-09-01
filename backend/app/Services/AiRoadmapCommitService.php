<?php

namespace App\Services;

use App\Models\Edge;
use App\Models\Node;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Support\Facades\DB;
use RuntimeException;

class AiRoadmapCommitService
{
    public function __construct(
        private VersionCloner $cloner,
        private VersionGraphRules $rules,
        private LearningRunLauncher $learning
    ) {
    }

    /**
     * @param array $proposal the "data" object from the AI service response
     */
    public function commit(RoadmapAiWorkflow $workflow, array $proposal): RoadmapVersion
    {
        $suggestions   = $proposal['node_suggestions_with_ids'] ?? [];
        $placements    = $proposal['placement_suggestions']     ?? [];
        $edgesToRemove = $proposal['existing_edges_to_remove']  ?? [];

        if (empty($placements)) {
            throw new RuntimeException('This proposal places no modules, so there is nothing to commit.');
        }

        // Membership in this list is the only safe way to tell an invented
        // node from a real one - the AI's temporary ids are just
        // max(existing)+1, which collides with real ids from other roadmaps.
        $suggestedById = [];

        foreach ($suggestions as $suggestion) {
            $suggestedById[(int) $suggestion['id']] = $suggestion;
        }

        $source = $workflow->roadmapVersion;

        return DB::transaction(function () use ($source, $workflow, $placements, $edgesToRemove, $suggestedById) {
            $version = RoadmapVersion::create([
                'roadmap_id'     => $source->roadmap_id,
                'version_number' => (string) (RoadmapVersion::where('roadmap_id', $source->roadmap_id)->count() + 1),
                'title'          => 'AI proposal ' . now()->format('Y-m-d'),
                'status'         => 'Draft',
            ]);

            // Start from the graph the AI actually reasoned about.
            $this->cloner->copy($source, $version);

            // temporary AI id => real nodes.id
            $realIdFor = [];

            // The modules this commit invents, so the learning workflow can be
            // pointed at them once the graph is written.
            $newVersionNodes = [];

            foreach ($placements as $placement) {
                $tempId = (int) $placement['suggested_node_id'];

                if (! isset($suggestedById[$tempId])) {
                    throw new RuntimeException("Placement refers to unknown suggested node {$tempId}.");
                }

                if (isset($realIdFor[$tempId])) {
                    continue;
                }

                $suggestion = $suggestedById[$tempId];

                $node = Node::create([
                    'title'       => $suggestion['title'],
                    'description' => $suggestion['description'],
                    'difficulty'  => $suggestion['difficulty'],
                    'status'      => 'approved',
                ]);

                $realIdFor[$tempId] = $node->id;

                // Announced, not yet teachable. The learning workflow is
                // started for it below; until that material is approved the
                // module is visible to students but cannot be entered, and
                // the publish gate lets the version go live regardless.
                $newVersionNodes[] = VersionNode::create([
                    'roadmap_version_id' => $version->id,
                    'node_id'            => $node->id,
                    'coming_soon'        => true,
                ]);
            }

            // Every node exists now, so references in either direction resolve.
            foreach ($placements as $placement) {
                $newNodeId = $realIdFor[(int) $placement['suggested_node_id']];

                foreach ($placement['direct_prerequisite_node_ids'] ?? [] as $id) {
                    $this->attachEdge($version, $this->resolve((int) $id, $realIdFor, $suggestedById, $version), $newNodeId);
                }

                foreach ($placement['direct_successor_node_ids'] ?? [] as $id) {
                    $this->attachEdge($version, $newNodeId, $this->resolve((int) $id, $realIdFor, $suggestedById, $version));
                }
            }

            foreach ($edgesToRemove as $edge) {
                $this->detachEdge($version, (int) $edge['from_node_id'], (int) $edge['to_node_id']);
            }

            $this->rules->assertValid($version);

            $workflow->update([
                'status'               => 'committed',
                'committed_version_id' => $version->id,
            ]);

            // Each new module goes straight to the learning workflow, so its
            // tutorial, quiz and project are being written before anyone asks.
            // Outside the graph work above but inside the transaction: if the
            // AI service is unreachable the runs simply are not queued, and
            // the modules stay coming-soon until someone starts them by hand.
            $this->queueLearningRuns($newVersionNodes);

            return $version;
        });
    }

    /**
     * Point the learning workflow at each module this commit invented.
     *
     * Failures are swallowed by the launcher: a manager has just approved a
     * roadmap, and an unreachable AI service should leave the modules marked
     * coming-soon and startable by hand rather than undo that.
     *
     * @param array<int,\App\Models\VersionNode> $versionNodes
     */
    private function queueLearningRuns(array $versionNodes): void
    {
        foreach ($versionNodes as $versionNode) {
            $this->learning->start($versionNode);
        }
    }

    /**
     * An id is either one the AI invented, or one of ours that must already
     * be part of this version.
     */
    private function resolve(int $id, array $realIdFor, array $suggestedById, RoadmapVersion $version): int
    {
        if (isset($suggestedById[$id])) {
            if (! isset($realIdFor[$id])) {
                throw new RuntimeException("Suggested node {$id} is referenced but was never placed.");
            }

            return $realIdFor[$id];
        }

        $inVersion = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $id)
            ->exists();

        if (! $inVersion) {
            throw new RuntimeException("Node {$id} is not part of this version.");
        }

        return $id;
    }

    private function attachEdge(RoadmapVersion $version, int $fromNodeId, int $toNodeId): void
    {
        if ($fromNodeId === $toNodeId) {
            throw new RuntimeException("Node {$fromNodeId} cannot be its own prerequisite.");
        }

        $edge = Edge::firstOrCreate([
            'from_node_id' => $fromNodeId,
            'to_node_id'   => $toNodeId,
        ]);

        VersionEdge::firstOrCreate([
            'roadmap_version_id' => $version->id,
            'edge_id'            => $edge->id,
        ]);
    }

    private function detachEdge(RoadmapVersion $version, int $fromNodeId, int $toNodeId): void
    {
        $edge = Edge::where('from_node_id', $fromNodeId)
            ->where('to_node_id', $toNodeId)
            ->first();

        if (! $edge) {
            return;
        }

        VersionEdge::where('roadmap_version_id', $version->id)
            ->where('edge_id', $edge->id)
            ->delete();
    }
}
