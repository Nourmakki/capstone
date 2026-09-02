<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadmapBuilderRun;
use App\Services\AiRoadmapBuilderCommitService;
use App\Services\AiWorkflowService;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * The AI system's roadmap builder: a whole subject from a topic name.
 *
 * The fourth of her workflows and the last to be connected. The other roadmap
 * workflow extends an existing graph and refuses an empty one, so until now a
 * new roadmap had to be seeded by hand before the AI could touch it.
 *
 * Two human review stages, both hers: scope first - is this the right set of
 * paths - then the roadmaps themselves. Nothing reaches our tables until a
 * manager commits.
 */
class RoadmapBuilderController extends Controller
{
    public function __construct(
        private AiWorkflowService $ai,
        private AiRoadmapBuilderCommitService $commitService
    ) {
    }

    /** Runs a manager might still want to act on, newest first. */
    public function index(): JsonResponse
    {
        $runs = RoadmapBuilderRun::with('committedRoadmap', 'starter')
            ->orderByDesc('id')
            ->take(30)
            ->get();

        return response()->json([
            'awaiting_review' => $runs->whereIn('status', RoadmapBuilderRun::ACTIVE_STATUSES)->count(),
            'runs'            => $runs->map(fn ($run) => $this->summary($run))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'topic' => ['required', 'string', 'min:2', 'max:255'],
        ]);

        // One at a time per topic: the builder is expensive, and two runs on
        // "web development" would commit two sets of the same paths.
        $active = RoadmapBuilderRun::whereRaw('LOWER(topic) = ?', [mb_strtolower(trim($data['topic']))])
            ->whereIn('status', RoadmapBuilderRun::ACTIVE_STATUSES)
            ->exists();

        if ($active) {
            return response()->json([
                'message' => 'A run for that topic is already waiting for review.',
            ], 409);
        }

        return $this->proxy(function () use ($data, $request) {
            $response = $this->ai->startRoadmapBuilder($data['topic']);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            $run = RoadmapBuilderRun::create([
                'topic'        => $data['topic'],
                'thread_id'    => $body['thread_id'],
                'status'       => $body['status'],
                'review_stage' => $body['review_stage'] ?? null,
                'started_by'   => $request->user()->id,
            ]);

            return response()->json([
                'message' => 'Roadmap builder started.',
                'run_id'  => $run->id,
                'data'    => $body,
            ], 201);
        });
    }

    /** The run as her service currently sees it, not our cached snapshot. */
    public function show(RoadmapBuilderRun $run): JsonResponse
    {
        return $this->proxy(function () use ($run) {
            $response = $this->ai->getRoadmapBuilder($run->thread_id);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            $run->update([
                'status'       => $run->isCommitted() ? $run->status : $body['status'],
                'review_stage' => $body['review_stage'] ?? null,
            ]);

            return response()->json(
                $this->withApproved($run, $body) + ['run' => $this->summary($run->fresh())]
            );
        });
    }

    /** Answer one of her review stages. */
    public function resume(Request $request, RoadmapBuilderRun $run): JsonResponse
    {
        $data = $request->validate([
            'action'   => ['required', Rule::in(['approve', 'edit', 'regenerate', 'reject'])],
            'stage'    => ['nullable', Rule::in(['scope', 'roadmap'])],
            'data'     => ['nullable', 'array'],
            'feedback' => ['nullable', 'string', 'max:4000'],
        ]);

        if ($run->isCommitted()) {
            return response()->json([
                'message' => 'This run has already been committed, so it cannot be reviewed again.',
            ], 409);
        }

        return $this->proxy(function () use ($run, $data) {
            // The roadmap being decided on, captured before the decision is
            // sent: approving moves her workflow to the next one, and she
            // only reports the approved set once the whole run is finished.
            // Without keeping it here, a manager reviewing roadmap three
            // cannot see the two they already accepted.
            // Only at the roadmap stage: the scope stage is answering "are
            // these the right roadmaps", and there is no package written yet
            // to keep. Asking for one there was a wasted call to her service
            // on every scope approval.
            $answeringRoadmap = ($data['stage'] ?? null) === 'roadmap'
                || (($data['stage'] ?? null) === null && $run->review_stage === 'roadmap_review');

            $current = ($data['action'] === 'approve' && $answeringRoadmap)
                ? $this->currentPackage($run)
                : null;

            $response = $this->ai->resumeRoadmapBuilder(
                $run->thread_id,
                array_filter($data, fn ($value) => $value !== null)
            );

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            $run->update([
                'status'            => $body['status'],
                'review_stage'      => $body['review_stage'] ?? null,
                'approved_packages' => $current
                    ? [...($run->approved_packages ?? []), $current]
                    : $run->approved_packages,
            ]);

            return response()->json($this->withApproved($run, $body));
        });
    }

    /**
     * Write the approved package into our tables.
     *
     * The result is read from her service rather than from anything we cached,
     * so a manager cannot commit a version of the package that has since been
     * edited or regenerated.
     */
    public function commit(RoadmapBuilderRun $run): JsonResponse
    {
        if ($run->isCommitted()) {
            return response()->json([
                'message'    => 'This run has already been committed.',
                'roadmap_id' => $run->committed_roadmap_id,
            ], 409);
        }

        return $this->proxy(function () use ($run) {
            $response = $this->ai->getRoadmapBuilder($run->thread_id);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            if (($body['status'] ?? null) !== 'ready_for_commit') {
                return response()->json([
                    'message' => 'This run is not ready to commit. It is currently '
                        . ($body['status'] ?? 'unknown') . '.',
                ], 422);
            }

            $roadmap = $this->commitService->commit($run, $body);

            return response()->json([
                'message'    => 'Roadmap committed. It is a draft awaiting review.',
                'roadmap_id' => $roadmap->id,
                'run'        => $this->summary($run->fresh()),
            ]);
        });
    }

    /**
     * Her service being down is not a 500 on our side.
     *
     * Same shape the other three workflows use: a clean 503 saying what is
     * unreachable, rather than a raw cURL error surfacing in the UI.
     */
    private function proxy(callable $call): JsonResponse
    {
        try {
            return $call();
        } catch (ConnectionException $e) {
            return response()->json([
                'message' => 'The AI system is not responding. Check that it is running, then try again.',
            ], 503);
        }
    }

    /**
     * The roadmap her workflow is currently asking about, if any.
     *
     * Read from her service rather than from the request: the manager is
     * approving what the page showed them, and the page reads the same
     * endpoint, so this is the same object.
     */
    private function currentPackage(RoadmapBuilderRun $run): ?array
    {
        try {
            $response = $this->ai->getRoadmapBuilder($run->thread_id);

            return $response->successful()
                ? ($response->json()['data']['roadmap_package'] ?? null)
                : null;
        } catch (\Throwable) {
            // Losing the record of an approved roadmap is not a reason to
            // refuse the approval itself.
            return null;
        }
    }

    /**
     * Fold what we kept into what she reports.
     *
     * Hers wins once the run finishes and she fills the list herself; ours is
     * what fills the gap during the review stages, when hers is empty.
     */
    private function withApproved(RoadmapBuilderRun $run, array $body): array
    {
        $hers = $body['data']['approved_roadmap_packages'] ?? [];

        if (! empty($hers) || empty($run->approved_packages)) {
            return $body;
        }

        $body['data']['approved_roadmap_packages'] = $run->approved_packages;

        return $body;
    }

    private function summary(RoadmapBuilderRun $run): array
    {
        return [
            'id'           => $run->id,
            'topic'        => $run->topic,
            'thread_id'    => $run->thread_id,
            'status'       => $run->status,
            'review_stage' => $run->review_stage,
            'committed'    => $run->isCommitted(),
            'roadmap'      => $run->committedRoadmap
                ? ['id' => $run->committedRoadmap->id, 'title' => $run->committedRoadmap->title]
                : null,
            'started_by'   => $run->starter->name ?? null,
            'created_at'   => $run->created_at,
        ];
    }
}
