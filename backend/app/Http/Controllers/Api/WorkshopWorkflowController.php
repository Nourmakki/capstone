<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use App\Models\WorkshopAiWorkflow;
use App\Services\AiWorkflowService;
use App\Services\AiWorkshopCommitService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Request;
use RuntimeException;

/**
 * The workshop agent, as a manager drives it.
 *
 * Ask it what is on for a roadmap's subject, read what it found, approve all
 * of it or some of it, then write the approved ones onto the version. Her
 * review takes only approve and reject - there is no editing a listing off
 * the web, and no regenerating one workshop out of six.
 */
class WorkshopWorkflowController extends Controller
{
    public function __construct(
        private AiWorkflowService $ai,
        private AiWorkshopCommitService $commitService
    ) {
    }

    public function index(): JsonResponse
    {
        $runs = WorkshopAiWorkflow::with('roadmapVersion.roadmap', 'versionNode.node', 'starter')
            ->orderByDesc('id')
            ->take(30)
            ->get();

        return response()->json([
            'awaiting_review' => $runs->whereIn('status', WorkshopAiWorkflow::ACTIVE_STATUSES)->count(),
            'runs'            => $runs->map(fn ($run) => $this->summary($run))->values(),
        ]);
    }

    /**
     * Ask for workshops on one version's subject.
     *
     * The topic is the roadmap's own title unless one is given: her agent
     * searches on it, and "Frontend" finds more than a module name would.
     * A module can be named too, which is what the student page matches a
     * recommendation against.
     */
    public function store(Request $request, RoadmapVersion $version): JsonResponse
    {
        $data = $request->validate([
            'topic'           => ['sometimes', 'string', 'min:2', 'max:255'],
            'version_node_id' => ['sometimes', 'nullable', 'integer', 'exists:version_nodes,id'],
        ]);

        $version->loadMissing('roadmap');

        $topic = $data['topic'] ?? $version->roadmap->title ?? null;

        if (! $topic) {
            return response()->json([
                'message' => 'This version has no roadmap to take a topic from. Give one.',
            ], 422);
        }

        // A module has to belong to the version it is being recommended for,
        // or the student page would match it against a roadmap it is not on.
        $nodeId = $data['version_node_id'] ?? null;

        if ($nodeId && ! VersionNode::where('id', $nodeId)
            ->where('roadmap_version_id', $version->id)
            ->exists()) {
            return response()->json([
                'message' => 'That module is not part of this version.',
            ], 422);
        }

        return $this->proxy(function () use ($request, $version, $topic, $nodeId) {
            // No location. A manager approves what is worth approving and
            // the student page is what narrows it: its For You tab matches a
            // workshop's country against the student's own. Searching
            // near one place here would decide for every student at once,
            // and quietly hide an online conference from all of them.
            $response = $this->ai->startWorkshopWorkflow($topic);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            $run = WorkshopAiWorkflow::create([
                'roadmap_version_id' => $version->id,
                'version_node_id'    => $nodeId,
                'thread_id'          => $body['thread_id'],
                'topic'              => $topic,
                'status'             => $body['status'] ?? 'awaiting_review',
                'review_stage'       => $body['review_stage'] ?? null,
                'started_by'         => $request->user()->id,
            ]);

            return response()->json($body + ['run' => $this->summary($run)], 201);
        });
    }

    public function show(WorkshopAiWorkflow $run): JsonResponse
    {
        return $this->proxy(function () use ($run) {
            $response = $this->ai->getWorkshopWorkflow($run->thread_id);

            if ($response->successful()) {
                $this->syncStatus($run, $response->json());
            }

            return response()->json($response->json(), $response->status());
        });
    }

    /**
     * Answer her review: approve all, approve some, or reject.
     *
     * Validated here rather than left to the round trip, because her model
     * forbids extra keys and answers a wrong shape with a Pydantic error.
     * Approve carries no feedback and no stage; reject carries no data.
     */
    public function resume(Request $request, WorkshopAiWorkflow $run): JsonResponse
    {
        $data = $request->validate([
            'action'                   => ['required', 'in:approve,reject'],
            'approved_workshop_links'  => ['sometimes', 'array', 'min:1', 'max:6'],
            'approved_workshop_links.*' => ['string'],
            'feedback'                 => ['sometimes', 'nullable', 'string'],
        ]);

        if ($run->isCommitted()) {
            return response()->json([
                'message' => 'This run has already been added to the roadmap.',
            ], 409);
        }

        $decision = ['action' => $data['action']];

        if ($data['action'] === 'approve') {
            $links = $data['approved_workshop_links'] ?? null;

            // Absent means all of them, which is her contract: data=null
            // approves everything proposed.
            if ($links !== null) {
                $unique = array_values(array_unique($links));

                if (count($unique) !== count($links)) {
                    return response()->json([
                        'message' => 'The same workshop was approved twice.',
                    ], 422);
                }

                $decision['data'] = ['approved_workshop_links' => $unique];
            }
        } elseif (! empty($data['feedback'])) {
            $decision['feedback'] = $data['feedback'];
        }

        return $this->proxy(function () use ($decision, $run) {
            $response = $this->ai->resumeWorkshopWorkflow($run->thread_id, $decision);

            if ($response->successful()) {
                $this->syncStatus($run, $response->json());
            }

            return response()->json($response->json(), $response->status());
        });
    }

    /**
     * Write the approved workshops onto the version.
     *
     * Her ready_for_commit response carries only the approved ones, so this
     * takes what it is given rather than filtering again.
     */
    public function commit(WorkshopAiWorkflow $run): JsonResponse
    {
        if ($run->isCommitted()) {
            return response()->json([
                'message' => 'This run has already been added.',
            ], 409);
        }

        return $this->proxy(function () use ($run) {
            $response = $this->ai->getWorkshopWorkflow($run->thread_id);

            if (! $response->successful()) {
                return response()->json($response->json(), $response->status());
            }

            $body = $response->json();

            if (($body['status'] ?? null) !== 'ready_for_commit') {
                return response()->json([
                    'message' => 'These workshops are not approved yet.',
                ], 409);
            }

            try {
                $written = $this->commitService->commit($run, $body['data'] ?? []);
            } catch (RuntimeException $e) {
                return response()->json(['message' => $e->getMessage()], 422);
            }

            return response()->json([
                'message' => $written === 1
                    ? 'One workshop added to the roadmap.'
                    : "{$written} workshops added to the roadmap.",
                'created' => $written,
            ], 201);
        });
    }

    /**
     * Take a run off the list.
     *
     * The row only. Workshops a committed run wrote are rows of their own on
     * the version, and stay - the same line the learning and builder runs
     * take.
     */
    public function destroy(WorkshopAiWorkflow $run): JsonResponse
    {
        $topic = $run->topic;

        $run->delete();

        return response()->json([
            'message' => "Removed the workshop search for \"{$topic}\". "
                . 'Any workshops it added are still on the roadmap.',
        ]);
    }

    /** Keep our row in step with whatever her state says. */
    private function syncStatus(WorkshopAiWorkflow $run, ?array $body): void
    {
        if (! is_array($body)) {
            return;
        }

        // Never back to one of hers once committed: committing is a fact on
        // this side, and she has no idea it happened.
        if ($run->isCommitted()) {
            return;
        }

        $run->update([
            'status'       => $body['status'] ?? $run->status,
            'review_stage' => $body['review_stage'] ?? null,
        ]);
    }

    private function summary(WorkshopAiWorkflow $run): array
    {
        return [
            'id'           => $run->id,
            'thread_id'    => $run->thread_id,
            'topic'        => $run->topic,
            'status'       => $run->status,
            'review_stage' => $run->review_stage,
            'awaiting'     => $run->status === 'awaiting_review',
            'ready'        => $run->status === 'ready_for_commit',
            'committed'    => $run->isCommitted(),
            'roadmap'      => $run->roadmapVersion->roadmap->title ?? 'Unknown',
            'version_id'   => $run->roadmap_version_id,
            'module'       => $run->versionNode->node->title ?? null,
            'started_by'   => $run->starter->name ?? null,
            'created_at'   => $run->created_at,
        ];
    }

    private function proxy(callable $callback): JsonResponse
    {
        try {
            return $callback();
        } catch (ConnectionException $e) {
            report($e);

            return response()->json([
                'message'       => 'The AI service is not reachable right now.',
                'service_error' => true,
            ], 503);
        }
    }
}
