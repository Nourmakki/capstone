<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NodeLearningWorkflow;
use App\Models\VersionNode;
use App\Services\AiLearningCommitService;
use App\Services\AiWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;
use Throwable;

class LearningWorkflowController extends Controller
{
    public function __construct(private AiWorkflowService $ai)
    {
    }

    public function index(): JsonResponse
    {
        $runs = NodeLearningWorkflow::with('versionNode.node', 'versionNode.roadmapVersion.roadmap')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($w) => [
                'id'           => $w->id,
                'thread_id'    => $w->thread_id,
                'status'       => $w->status,
                'awaiting'     => $w->status === 'awaiting_review',
                'committed'    => $w->committed_at !== null,
                'module'       => $w->versionNode->node->title ?? 'Unknown',
                'roadmap'      => $w->versionNode->roadmapVersion->roadmap->title ?? 'Unknown',
                'created_at'   => $w->created_at,
            ]);

        return response()->json([
            'runs'            => $runs,
            'awaiting_review' => $runs->where('awaiting', true)->count(),
        ]);
    }

    /**
     * Generation runs per module, so the payload describes one node and what
     * comes before it - the AI needs that to avoid repeating earlier material.
     */
    public function store(VersionNode $versionNode): JsonResponse
    {
        $active = NodeLearningWorkflow::where('version_node_id', $versionNode->id)
            ->whereNull('committed_at')
            ->whereIn('status', ['awaiting_review', 'ready'])
            ->exists();

        if ($active) {
            return response()->json([
                'message' => 'This module already has a run awaiting review.',
            ], 409);
        }

        $versionNode->load('node', 'roadmapVersion.roadmap');

        $prerequisites = ProgressController::prerequisiteNodesFor($versionNode);

        $payload = [
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
            'prerequisite_nodes' => $prerequisites,
        ];

        return $this->proxy(function () use ($payload, $versionNode) {
            $response = $this->ai->startLearningWorkflow($payload);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $data = $response->json();

            $run = NodeLearningWorkflow::create([
                'version_node_id' => $versionNode->id,
                'thread_id'       => $data['thread_id'],
                'status'          => $data['status'],
                'review_stage'    => $data['review_stage'] ?? null,
            ]);

            return response()->json([
                'message' => 'Generation started.',
                'run_id'  => $run->id,
                'data'    => $data,
            ], 201);
        });
    }

    public function show(NodeLearningWorkflow $run): JsonResponse
    {
        return $this->proxy(function () use ($run) {
            $response = $this->ai->getLearningWorkflow($run->thread_id);

            if ($response->successful()) {
                $data = $response->json();

                $run->update([
                    'status'       => $data['status'] ?? $run->status,
                    'review_stage' => $data['review_stage'] ?? null,
                ]);
            }

            return response()->json($response->json(), $response->status());
        });
    }

    public function resume(Request $request, NodeLearningWorkflow $run): JsonResponse
    {
        $decision = $request->all();

        return $this->proxy(function () use ($decision, $run) {
            $response = $this->ai->resumeLearningWorkflow($run->thread_id, $decision);

            if ($response->successful()) {
                $data = $response->json();

                $run->update([
                    'status'       => $data['status'] ?? $run->status,
                    'review_stage' => $data['review_stage'] ?? null,
                ]);
            }

            return response()->json($response->json(), $response->status());
        });
    }

    public function commit(NodeLearningWorkflow $run): JsonResponse
    {
        if ($run->committed_at) {
            return response()->json([
                'message' => 'This material has already been committed.',
            ], 409);
        }

        return $this->proxy(function () use ($run) {
            $response = $this->ai->getLearningWorkflow($run->thread_id);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            // Her learning workflow ends at "ready" - the roadmap one says
            // "ready_for_commit". Two words for the same state.
            if (($body['status'] ?? null) !== 'ready') {
                return response()->json([
                    'message' => 'This material is not approved yet.',
                ], 409);
            }

            try {
                $created = app(AiLearningCommitService::class)->commit($run, $body['data'] ?? []);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'message' => 'Material added to the module, awaiting your review.',
                'created' => $created,
            ], 201);
        });
    }

    private function proxy(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (Throwable $e) {
            report($e);

            return response()->json([
                'message'       => 'The AI service is not reachable right now.',
                'service_error' => true,
            ], 503);
        }
    }
}