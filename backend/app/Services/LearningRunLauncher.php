<?php

namespace App\Services;

use App\Http\Controllers\Api\ProgressController;
use App\Models\NodeLearningWorkflow;
use App\Models\VersionNode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;

/**
 * Ask the AI system to write a module's tutorial, quiz, project and resources.
 *
 * Two callers want this: a manager pressing Generate, and the roadmap commit
 * that has just invented modules and wants their material under way before
 * anyone asks for it. Shared so the payload they send is the same one - a
 * second copy would drift from her schema the first time it changed.
 */
class LearningRunLauncher
{
    public function __construct(private AiWorkflowService $ai)
    {
    }

    /** A run is already under way for this module. */
    public function hasActiveRun(VersionNode $versionNode): bool
    {
        return NodeLearningWorkflow::where('version_node_id', $versionNode->id)
            ->whereNull('committed_at')
            ->whereIn('status', ['awaiting_review', 'ready'])
            ->exists();
    }

    /**
     * Her StartLearningWorkflow input: one module, and what comes before it so
     * the material does not repeat what a student has already covered.
     */
    public function payloadFor(VersionNode $versionNode): array
    {
        $versionNode->load('node', 'roadmapVersion.roadmap');

        return [
            'roadmap_id'         => $versionNode->roadmapVersion->roadmap_id,
            'roadmap_topic'      => strtolower($versionNode->roadmapVersion->roadmap->title),
            'version_id'         => $versionNode->roadmap_version_id,
            'version_number'     => (string) $versionNode->roadmapVersion->version_number,
            'node'               => [
                'id'          => $versionNode->node_id,
                'title'       => $versionNode->node->title,
                'description' => $versionNode->node->description,
                'difficulty'  => $versionNode->node->difficulty,
            ],
            'prerequisite_nodes' => ProgressController::prerequisiteNodesFor($versionNode),
        ];
    }

    /**
     * Start a run and record it, or return null.
     *
     * Null rather than an exception because the caller that matters here is a
     * commit that has already written a roadmap: failing to reach the AI
     * should leave the modules marked coming-soon and startable by hand, not
     * roll back work a manager just approved.
     */
    public function start(VersionNode $versionNode): ?NodeLearningWorkflow
    {
        if ($this->hasActiveRun($versionNode)) {
            return null;
        }

        try {
            $response = $this->ai->startLearningWorkflow($this->payloadFor($versionNode));
        } catch (ConnectionException $e) {
            Log::warning('Learning run could not be started: the AI system is unreachable.', [
                'version_node_id' => $versionNode->id,
            ]);

            return null;
        }

        if (! $response->successful()) {
            Log::warning('Learning run refused by the AI system.', [
                'version_node_id' => $versionNode->id,
                'status'          => $response->status(),
            ]);

            return null;
        }

        $data = $response->json();

        return NodeLearningWorkflow::create([
            'version_node_id' => $versionNode->id,
            'thread_id'       => $data['thread_id'],
            'status'          => $data['status'],
            'review_stage'    => $data['review_stage'] ?? null,
        ]);
    }
}
