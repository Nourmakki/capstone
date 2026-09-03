<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ResumeAiWorkflowRequest;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Services\AiWorkflowService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AiWorkflowController extends Controller
{
    /**
     * Statuses that mean a run is still in flight, so a second run on the
     * same version would collide with it.
     */
    private const ACTIVE_STATUSES = ['awaiting_review', 'ready_for_commit'];

    public function __construct(
        private AiWorkflowService $aiWorkflowService
    ) {
    }

    /**
     * Every workflow, newest first, for the reviewer's queue.
     */
    public function index(): JsonResponse
    {
        $workflows = RoadmapAiWorkflow::with('roadmapVersion.roadmap')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($w) => [
                'id'             => $w->id,
                'thread_id'      => $w->thread_id,
                'status'         => $w->status,
                'review_stage'   => $w->review_stage,
                'awaiting'       => $w->status === 'awaiting_review',
                'committed_version_id' => $w->committed_version_id,
                'roadmap'        => $w->roadmapVersion->roadmap->title ?? 'Unknown',
                'version_label'  => 'v' . ($w->roadmapVersion->version_number ?? '?'),
                'version_id'     => $w->roadmap_version_id,
                'created_at'     => $w->created_at,
                'updated_at'     => $w->updated_at,
            ]);

        return response()->json([
            'workflows'         => $workflows,
            'awaiting_review'   => $workflows->where('awaiting', true)->count(),
        ]);
    }

    /**
     * Kick off a run for one roadmap version.
     */
    public function store(RoadmapVersion $version): JsonResponse
    {
        $active = RoadmapAiWorkflow::where('roadmap_version_id', $version->id)
            ->whereIn('status', self::ACTIVE_STATUSES)
            ->exists();

        if ($active) {
            return response()->json([
                'message' => 'This version already has a workflow awaiting review.',
            ], 409);
        }

        // Same payload the scheduled command sends.
        $payload = app(RoadmapController::class)->aiExport($version)->getData(true);

        return $this->proxy(function () use ($payload, $version) {
            $response = $this->aiWorkflowService->startWorkflow($payload);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $data = $response->json();

            $workflow = RoadmapAiWorkflow::create([
                'roadmap_version_id' => $version->id,
                'thread_id'          => $data['thread_id'],
                'status'             => $data['status'],
                'review_stage'       => $data['review_stage'] ?? null,
            ]);

            return response()->json([
                'message'     => 'Workflow started.',
                'workflow_id' => $workflow->id,
                'data'        => $data,
            ], 201);
        });
    }

    public function show(RoadmapAiWorkflow $workflow): JsonResponse
    {
        return $this->proxy(function () use ($workflow) {
            $response = $this->aiWorkflowService->getWorkflow($workflow->thread_id);

            // Keep the local row in step with the AI service.
            if ($response->successful()) {
                $data = $response->json();

                $workflow->update([
                    'status'       => $data['status'] ?? $workflow->status,
                    'review_stage' => $data['review_stage'] ?? null,
                ]);
            }

            return response()->json($response->json(), $response->status());
        });
    }

    public function resume(ResumeAiWorkflowRequest $request, RoadmapAiWorkflow $workflow): JsonResponse
    {
        return $this->proxy(function () use ($request, $workflow) {
            $response = $this->aiWorkflowService->resumeWorkflow(
                $workflow->thread_id,
                $request->validated()
            );

            if ($response->successful()) {
                $data = $response->json();

                $workflow->update([
                    'status'       => $data['status'] ?? $workflow->status,
                    'review_stage' => $data['review_stage'] ?? null,
                ]);
            }

            return response()->json($response->json(), $response->status());
        });
    }

    /**
     * Turn an approved proposal into a new version, and put it in front of
     * students with its new modules marked coming-soon.
     */
    public function commit(Request $request, RoadmapAiWorkflow $workflow): JsonResponse
    {
        if ($workflow->committed_version_id) {
            return response()->json([
                'message' => 'This proposal has already been committed.',
            ], 409);
        }

        return $this->proxy(function () use ($request, $workflow) {
            // Read the live state rather than our cached row - the reviewer
            // may have edited the proposal since we last synced.
            $response = $this->aiWorkflowService->getWorkflow($workflow->thread_id);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            if (($body['status'] ?? null) !== 'ready_for_commit') {
                return response()->json([
                    'message' => 'This proposal is not approved yet.',
                ], 409);
            }

            try {
                $version = app(\App\Services\AiRoadmapCommitService::class)
                    ->commit($workflow, $body['data'] ?? [], $request->user());
            } catch (\RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            } catch (\Illuminate\Validation\ValidationException $e) {
                // A graph rule rejected it. Caught here so it does not reach
                // proxy(), which would report a bad proposal as an outage.
                return response()->json([
                    'message' => collect($e->errors())->flatten()->first(),
                ], 422);
            }

            return response()->json([
                'message'    => 'Committed as a new draft version.',
                'version_id' => $version->id,
                'version'    => 'v' . $version->version_number,
            ], 201);
        });
    }

    /**
     * The AI service is a separate process that is often simply not running.
     * Without this, a refused connection surfaces as a raw cURL stack trace.
     *
     * Report an unreachable AI service as such - and nothing else as that.
     *
     * This caught every Throwable and called all of them "the AI service is
     * not reachable". A manager committing a proposal hit a database error on
     * our own side and was told her partner's Python service was down; she
     * went looking at a service that was answering every request with a 200,
     * and the real fault - an unmigrated table - stayed hidden.
     *
     * Only a failure to connect is an outage. Anything else is our bug and
     * should say so, loudly, where it happened.
     */
    private function proxy(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (ConnectionException $e) {
            report($e);

            return response()->json([
                'message'        => 'The AI service is not reachable right now.',
                'service_error'  => true,
            ], 503);
        }
    }
}
