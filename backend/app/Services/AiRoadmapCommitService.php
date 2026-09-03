<?php

namespace App\Services;

use App\Jobs\StartLearningRun;
use App\Models\Edge;
use App\Models\Node;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Models\User;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
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
     * @param ?User $committedBy who pressed commit, recorded as the reviewer
     *                           when the version goes live
     */
    public function commit(
        RoadmapAiWorkflow $workflow,
        array $proposal,
        ?User $committedBy = null
    ): RoadmapVersion {
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

        $committed = DB::transaction(function () use ($source, $workflow, $placements, $edgesToRemove, $suggestedById, $committedBy) {
            $version = RoadmapVersion::create([
                'roadmap_id'     => $source->roadmap_id,
                'version_number' => (string) (RoadmapVersion::where('roadmap_id', $source->roadmap_id)->count() + 1),
                'title'          => 'AI proposal ' . now()->format('Y-m-d'),
                'status'         => 'Draft',
                // Readable as coming soon while its new modules are written.
                // goLive() below clears this when the version can publish for
                // real, so a version is never both announced and live.
                'announced'      => true,
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

            $this->goLive($version, $committedBy);

            return [$version, $newVersionNodes];
        });

        [$version, $newVersionNodes] = $committed;

        // Each new module goes straight to the learning workflow, so its
        // tutorial, quiz and project are being written before anyone asks.
        //
        // On the queue, and after the transaction has closed. Her learning
        // endpoint runs the whole graph before it answers - up to five
        // minutes for a single module - so starting them inline held the
        // commit open for as long as that took, inside a transaction, and
        // risked losing the whole commit to PHP's execution limit. Handing
        // them to the queue cannot fail a commit that is already written.
        $this->queueLearningRuns($newVersionNodes);

        return $version;
    }

    /**
     * Put the committed version in front of students.
     *
     * Committing is the manager saying yes to the shape of the roadmap, and
     * the new modules are marked coming-soon, so this makes them visible
     * without making them enterable: is_unlocked is false while coming_soon
     * is true, however far a student has got. They see what is arriving and
     * can reach none of it.
     *
     * Left as a draft instead, the whole point of coming-soon was lost - the
     * modules sat in a version nobody could see, and a student learned the
     * roadmap had grown only once the material was finished and it was
     * published, which is exactly when the mark was due to come off.
     *
     * What is still gated is the material: MaterialController::liftComingSoon
     * takes the mark off a module once its tutorial, quiz and project are
     * approved, and only then can it be worked through.
     *
     * The readiness rule still decides, but it cannot fail the commit.
     *
     * Some roadmaps were published before that rule existed and would not
     * satisfy it today - Frontend is live right now with two modules that
     * have no material at all. Throwing here would roll back the whole
     * transaction inside which this runs, so committing any proposal onto
     * such a roadmap would stop working. It stays a draft instead, exactly
     * as it did before, and the manager publishes it the usual way.
     */
    private function goLive(RoadmapVersion $version, ?User $committedBy): void
    {
        try {
            app(VersionPublishRules::class)->assertReadyToPublish($version);
        } catch (ValidationException) {
            return;
        }

        // One live version per roadmap: the one this was cloned from steps
        // aside rather than being served alongside it.
        RoadmapVersion::where('roadmap_id', $version->roadmap_id)
            ->where('id', '!=', $version->id)
            ->where('status', 'Published')
            ->update(['status' => 'Archived']);

        // approveBy rather than a raw update, so the reviewer is recorded the
        // same way the Content Queue records one - a version that went live
        // with nobody's name on it reads like it published itself.
        if ($committedBy) {
            $version->approveBy($committedBy, 'Committed from an AI proposal.');
        } else {
            $version->update(['review_status' => 'approved', 'reviewed_at' => now()]);
        }

        $version->update(['status' => 'Published']);
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
            StartLearningRun::dispatch($versionNode->id);
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
                // The proposal contradicts itself: one module is linked to
                // another that the AI never gave a position to. Named rather
                // than numbered, because the number is one of her temporary
                // ids and means nothing to the manager reading this.
                $title = $suggestedById[$id]['title'] ?? "module {$id}";

                throw new RuntimeException(
                    "The proposal links to \"{$title}\", but never says where that module goes. "
                    . 'Regenerate the placement stage, or remove the link, then commit again.'
                );
            }

            return $realIdFor[$id];
        }

        $inVersion = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $id)
            ->exists();

        if (! $inVersion) {
            // Her ids are ours, so this means she reasoned about a graph that
            // is not the one being committed to - usually a version that has
            // moved on since the run started.
            throw new RuntimeException(
                "The proposal links to a module that is not on this roadmap version "
                . "(id {$id}). The roadmap may have changed since this run started - "
                . 'start a new run against the current version.'
            );
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
