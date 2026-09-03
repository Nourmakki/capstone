<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\NodeLearningWorkflow;
use App\Models\VersionNode;
use App\Services\AiLearningCommitService;
use App\Services\AiWorkflowService;
use App\Services\LearningRunLauncher;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use RuntimeException;

class LearningWorkflowController extends Controller
{
    public function __construct(
        private AiWorkflowService $ai,
        private LearningRunLauncher $launcher
    ) {
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
        // The launcher owns both the guard and the payload, because the
        // roadmap commit starts these too and a second copy of her contract
        // would drift the first time it changed.
        if ($this->launcher->hasActiveRun($versionNode)) {
            return response()->json([
                'message' => 'This module already has a run awaiting review.',
            ], 409);
        }

        $payload = $this->launcher->payloadFor($versionNode);

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

            // All four workflows now end at "ready_for_commit". The learning
            // and assessment ones used to say "ready"; she unified the two
            // words in 042c157. The tutorial_result inside still has a
            // "ready" of its own - a different status on a different object.
            if (($body['status'] ?? null) !== 'ready_for_commit') {
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
        } catch (ConnectionException $e) {
            // Only a refused connection is an outage. Catching everything
            // here reported our own errors as her service being down, and
            // sent people to look at a service that was working.
            report($e);

            return response()->json([
                'message'       => 'The AI service is not reachable right now.',
                'service_error' => true,
            ], 503);
        }
    }
}