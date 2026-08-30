<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalResources;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use Illuminate\Http\Request;

class MaterialController extends Controller
{
    /**
     * The four material types this role reviews, keyed by the slug used
     * in the approve and reject routes.
     */
    private const TYPES = [
        'roadmaps'           => Roadmap::class,
        'roadmap-versions'   => RoadmapVersion::class,
        'tutorials'          => Tutorial::class,
        'external-resources' => ExternalResources::class,
        'quizzes'            => Quiz::class,
        'projects'           => Project::class,
    ];

    public function stats()
    {
        $counts = [];

        foreach (self::TYPES as $slug => $model) {
            $counts[$slug] = [
                'pending'  => $model::pendingReview()->count(),
                'approved' => $model::approved()->count(),
                'rejected' => $model::where('review_status', 'rejected')->count(),
            ];
        }

        $counts['total_pending'] = collect(self::TYPES)
            ->sum(fn ($model) => $model::pendingReview()->count());

        return response()->json($counts);
    }

    /**
     * Everything awaiting review, grouped by type.
     */
    public function pending()
    {
        return response()->json([
            'roadmaps' => Roadmap::pendingReview()
                ->orderBy('created_at')
                ->get()
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'title'       => $r->title,
                    'description' => $r->description,
                    'created_at'  => $r->created_at,
                ]),

            'roadmap_versions' => RoadmapVersion::pendingReview()
                ->with('roadmap')
                ->withCount('versionNodes')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($v) => [
                    'id'             => $v->id,
                    'title'          => ($v->roadmap->title ?? 'Unknown') . ' v' . $v->version_number,
                    'description'    => $v->version_nodes_count . ' modules · currently ' . $v->status,
                    'roadmap_id'     => $v->roadmap_id,
                    'version_number' => $v->version_number,
                    'created_at'     => $v->created_at,
                ]),

            'tutorials' => Tutorial::pendingReview()
                ->with('versionNode.node')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($t) => [
                    'id'          => $t->id,
                    'title'       => $t->title,
                    'description' => $t->description,
                    'url'         => $t->url,
                    'source'      => $t->source_name,
                    'topic'       => $t->versionNode->node->title ?? null,
                    'change_note' => $t->change_note,
                    'created_at'  => $t->created_at,
                ]),

            'external_resources' => ExternalResources::pendingReview()
                ->with('versionNode.node')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'title'       => $r->title,
                    'description' => ucfirst(str_replace('_', ' ', $r->type)),
                    'url'         => $r->url,
                    'topic'       => $r->versionNode->node->title ?? null,
                    'created_at'  => $r->created_at,
                ]),

            'quizzes' => Quiz::pendingReview()
                ->with('versionNode.node', 'questions')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($q) => [
                    'id'          => $q->id,
                    'title'       => $q->title,
                    'description' => $q->questions->count() . ' question(s)',
                    'topic'       => $q->versionNode->node->title ?? null,
                    // The reviewer needs to read the questions to judge them.
                    'questions'   => $q->questions->map(fn ($question) => [
                        'question' => $question->question,
                        'type'     => $question->type,
                        'options'  => $question->options ?? [],
                        'answer'   => $question->answer,
                    ]),
                    'change_note' => $q->change_note,
                    'created_at'  => $q->created_at,
                ]),

            'projects' => Project::pendingReview()
                ->with('versionNode.node')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($p) => [
                    'id'          => $p->id,
                    'title'       => $p->title,
                    'description' => $p->description,
                    'difficulty'  => $p->difficulty,
                    'url'         => $p->github_url,
                    'topic'       => $p->versionNode->node->title ?? null,
                    'change_note' => $p->change_note,
                    'created_at'  => $p->created_at,
                ]),
        ]);
    }

    /**
     * Recently reviewed material, so decisions are auditable.
     */
    public function history()
    {
        $items = collect();

        foreach (self::TYPES as $slug => $model) {
            $reviewed = $model::whereIn('review_status', ['approved', 'rejected'])
                ->whereNotNull('reviewed_at')
                ->with('reviewer')
                ->orderByDesc('reviewed_at')
                ->limit(20)
                ->get()
                ->map(fn ($item) => [
                    'type'        => $slug,
                    'id'          => $item->id,
                    'title'       => $item->title ?? ('#' . $item->id),
                    'status'      => $item->review_status,
                    'notes'       => $item->review_notes,
                    'reviewer'    => $item->reviewer->name ?? 'Unknown',
                    'reviewed_at' => $item->reviewed_at,
                ]);

            $items = $items->concat($reviewed);
        }

        return response()->json([
            'history' => $items->sortByDesc('reviewed_at')->take(40)->values(),
        ]);
    }

    public function approve(Request $request, string $type, int $id)
    {
        $item = $this->find($type, $id);

        $data = $request->validate([
            'notes' => ['nullable', 'string', 'max:2000'],
        ]);

        $item->approveBy($request->user(), $data['notes'] ?? null);

        // Approving a version is what makes it live, and only one version
        // of a roadmap may be published at a time.
        if ($item instanceof RoadmapVersion) {
            RoadmapVersion::where('roadmap_id', $item->roadmap_id)
                ->where('id', '!=', $item->id)
                ->where('status', 'Published')
                ->update(['status' => 'Archived']);

            $item->update(['status' => 'Published']);
        }

        return response()->json(['message' => 'Approved.']);
    }

    public function reject(Request $request, string $type, int $id)
    {
        $item = $this->find($type, $id);

        $data = $request->validate([
            // A rejection without a reason is not actionable.
            'notes' => ['required', 'string', 'min:5', 'max:2000'],
        ]);

        $item->rejectBy($request->user(), $data['notes']);

        if ($item instanceof RoadmapVersion) {
            $item->update(['status' => 'Draft']);
        }

        return response()->json(['message' => 'Rejected.']);
    }

    private function find(string $type, int $id)
    {
        abort_unless(isset(self::TYPES[$type]), 404, 'Unknown material type.');

        return self::TYPES[$type]::findOrFail($id);
    }
}
