<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
use App\Models\UserRoadmapProgress;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

/**
 * The student's question, answered by the RAG assistant.
 *
 * Laravel sits in the middle rather than letting the browser call port 8002
 * directly, and that is the whole point of this controller: it decides who is
 * asking. The assistant is told the student's progress, and it is told by the
 * platform that holds it - never by the client. A browser that could set that
 * field could ask about somebody else's progress by editing a request.
 *
 * The catalogue half of the answer comes from the assistant's index, built
 * from published and approved content. The personal half is assembled here,
 * per request, from live rows - and is never indexed, because a vector store
 * cannot tell one student's question from another's.
 */
class AssistantController extends Controller
{
    public function ask(Request $request): JsonResponse
    {
        $data = $request->validate([
            'question' => ['required', 'string', 'min:2', 'max:1000'],
        ]);

        try {
            $response = Http::acceptJson()
                ->connectTimeout(5)
                // Retrieval is local and fast; the model is the slow part.
                ->timeout(60)
                ->post(rtrim(config('services.assistant.url'), '/') . '/ask', [
                    'question' => $data['question'],
                    'student'  => $this->studentContext($request),
                ]);
        } catch (ConnectionException) {
            return response()->json([
                'message' => 'The assistant is not running right now. Everything else on '
                    . 'the platform still works.',
            ], 503);
        }

        if (! $response->successful()) {
            return response()->json([
                'message' => 'The assistant could not answer that. Try again in a moment.',
            ], 502);
        }

        return response()->json($response->json());
    }

    /**
     * Who is asking, and where they have got to.
     *
     * Null for anyone who is not a student - a professor or a manager asking
     * about the catalogue has no progress, and inventing an empty one would
     * have the assistant talk to them as though they were enrolled.
     */
    private function studentContext(Request $request): ?array
    {
        $user = $request->user();
        $student = Student::where('user_id', $user->id)->first();

        if (! $student) {
            return null;
        }

        $rows = UserRoadmapProgress::with('roadmapVersion.roadmap')
            ->where('student_id', $student->id)
            ->orderByDesc('updated_at')
            // The assistant answers "what should I do next", which is about
            // what a student is in the middle of - not a full transcript.
            ->take(5)
            ->get();

        return [
            'name'     => $user->name,
            'level'    => $student->level,
            'roadmaps' => $rows->map(fn ($p) => $this->roadmapProgress($p))->values()->all(),
        ];
    }

    /** One roadmap: how far through it, and what is open now. */
    private function roadmapProgress(UserRoadmapProgress $progress): array
    {
        $version   = $progress->roadmapVersion;
        $completed = $progress->completedNodes->pluck('node_id')->toArray();

        $nodes = VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->get();

        $prerequisites = [];

        foreach (VersionEdge::with('edge')->where('roadmap_version_id', $version->id)->get() as $versionEdge) {
            if ($versionEdge->edge) {
                $prerequisites[$versionEdge->edge->to_node_id][] = $versionEdge->edge->from_node_id;
            }
        }

        // The first module they have not finished whose prerequisites they
        // have - the same rule the roadmap page unlocks by, so the assistant
        // and the page cannot disagree about where a student is.
        $current = null;

        foreach ($nodes as $versionNode) {
            if (in_array($versionNode->node_id, $completed, true)) {
                continue;
            }

            if (empty(array_diff($prerequisites[$versionNode->node_id] ?? [], $completed))) {
                $current = $versionNode->node->title;
                break;
            }
        }

        return [
            'title'             => $version->roadmap->title ?? 'a roadmap',
            'completed_modules' => count($completed),
            'total_modules'     => $nodes->count(),
            'current_module'    => $current,
        ];
    }
}
