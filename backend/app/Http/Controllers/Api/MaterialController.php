<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalResources;
use App\Models\Project;
use App\Models\Quiz;
use App\Jobs\RebuildAssistantIndex;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\User;
use App\Models\VersionNode;
use App\Services\VersionPublishRules;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;

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
     * The subject a roadmap sits under, whatever its depth.
     *
     * Roadmaps nest up to three deep - Mobile Development holds Android
     * Development holds Kotlin - and the queue files everything under the
     * subject at the top, so one committed build reads as one folder rather
     * than as seventeen unrelated rows. A subject passed in is its own
     * answer; walking stops there.
     */
    private function subjectOf(?Roadmap $roadmap): ?string
    {
        if (! $roadmap) {
            return null;
        }

        // Bounded rather than while(true): a parent cycle in the data would
        // otherwise hang the request, and three is the depth the builder can
        // produce.
        for ($i = 0; $i < 5 && $roadmap->parent; $i++) {
            $roadmap = $roadmap->parent;
        }

        return $roadmap->title;
    }

    /**
     * Where a piece of material lives: its module, roadmap and subject.
     *
     * One helper for all four material types, because they hang off a
     * version node the same way and four copies of this would drift.
     */
    private function placeOf($item): array
    {
        $node    = $item->versionNode;
        $roadmap = $node->roadmapVersion->roadmap ?? null;

        return [
            'subject' => $this->subjectOf($roadmap),
            'roadmap' => $roadmap->title ?? null,
            'module'  => $node->node->title ?? null,
        ];
    }

    /**
     * Everything awaiting review, with where it belongs.
     *
     * Still keyed by type - that is what decides which endpoint approves an
     * item - but every entry now carries the subject, roadmap and module it
     * came from, so the queue can file them into folders. A manager reviewing
     * a newly built subject is looking at one tree, not a flat list of
     * roadmaps, versions and material that happen to be pending together.
     */
    public function pending()
    {
        return response()->json([
            'roadmaps' => Roadmap::pendingReview()
                ->with('parent.parent.parent')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'title'       => $r->title,
                    'description' => $r->description,
                    // A subject awaiting review is its own folder; anything
                    // deeper files under the subject above it.
                    'subject'     => $this->subjectOf($r),
                    'roadmap'     => $r->title,
                    'created_at'  => $r->created_at,
                ]),

            'roadmap_versions' => RoadmapVersion::pendingReview()
                ->with('roadmap.parent.parent.parent')
                ->withCount('versionNodes')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($v) => [
                    'id'             => $v->id,
                    'title'          => ($v->roadmap->title ?? 'Unknown') . ' v' . $v->version_number,
                    'description'    => $v->version_nodes_count . ' modules · currently ' . $v->status,
                    'roadmap_id'     => $v->roadmap_id,
                    'version_number' => $v->version_number,
                    'subject'        => $this->subjectOf($v->roadmap),
                    'roadmap'        => $v->roadmap->title ?? null,
                    'created_at'     => $v->created_at,
                ]),

            'tutorials' => Tutorial::pendingReview()
                ->with('versionNode.node', 'versionNode.roadmapVersion.roadmap.parent.parent.parent')
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
                ] + $this->placeOf($t)),

            'external_resources' => ExternalResources::pendingReview()
                ->with('versionNode.node', 'versionNode.roadmapVersion.roadmap.parent.parent.parent')
                ->orderBy('created_at')
                ->get()
                ->map(fn ($r) => [
                    'id'          => $r->id,
                    'title'       => $r->title,
                    'description' => ucfirst(str_replace('_', ' ', $r->type)),
                    'url'         => $r->url,
                    'topic'       => $r->versionNode->node->title ?? null,
                    'created_at'  => $r->created_at,
                ] + $this->placeOf($r)),

            'quizzes' => Quiz::pendingReview()
                ->with('versionNode.node', 'questions', 'versionNode.roadmapVersion.roadmap.parent.parent.parent')
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
                ] + $this->placeOf($q)),

            'projects' => Project::pendingReview()
                ->with('versionNode.node', 'versionNode.roadmapVersion.roadmap.parent.parent.parent')
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
                ] + $this->placeOf($p)),
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
                    // A tutorial is a video and a resource is a page, so the
                    // thing that was approved lives somewhere and can be
                    // reopened. Null for a quiz or a roadmap, which do not.
                    'url'         => $item->url ?? $item->github_url ?? null,
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

        $this->applyApproval($item, $request->user(), $data['notes'] ?? null);

        // What students can see has changed, so the assistant's index is now
        // one approval out of date. Queued, so the manager does not wait.
        RebuildAssistantIndex::dispatch();

        return response()->json(['message' => 'Approved.']);
    }

    /**
     * Approve a folder's worth of items in one press.
     *
     * The items are named explicitly rather than re-derived from a folder
     * name here: the manager is approving what the screen showed them, and a
     * subject re-queried server-side could have gained an item since the page
     * loaded that nobody has looked at.
     *
     * Ordered before anything is written, because approving is not
     * commutative - a version is checked against its roadmap being approved,
     * and lifting a module's coming-soon mark depends on its material. The
     * page lists them in this order too, but correctness should not rest on
     * the client having sorted them.
     *
     * Not a transaction, deliberately. One version failing the publish gate
     * is a normal outcome - it means a module still has no material - and
     * rolling back the fourteen items that were fine would make the manager
     * do them all again to find out which one it was. Everything that can be
     * approved is, and what could not is named back.
     */
    public function approveMany(Request $request)
    {
        $data = $request->validate([
            'items'        => ['required', 'array', 'min:1', 'max:100'],
            'items.*.type' => ['required', 'string', Rule::in(array_keys(self::TYPES))],
            'items.*.id'   => ['required', 'integer'],
            'notes'        => ['nullable', 'string', 'max:2000'],
        ]);

        $order = array_flip(array_keys(self::TYPES));

        $items = collect($data['items'])
            ->sortBy(fn ($entry) => $order[$entry['type']])
            ->values();

        $approved = 0;
        $failed   = [];

        foreach ($items as $entry) {
            try {
                $item = $this->find($entry['type'], $entry['id']);
            } catch (ModelNotFoundException) {
                // Already gone, or decided on in another tab. Not an error
                // worth stopping for - the outcome asked for is the one that
                // already holds.
                continue;
            }

            try {
                $this->applyApproval($item, $request->user(), $data['notes'] ?? null);
                $approved++;
            } catch (ValidationException $e) {
                $failed[] = [
                    'type'   => $entry['type'],
                    'id'     => $entry['id'],
                    'title'  => $item->title ?? ('#' . $item->id),
                    'reason' => collect($e->errors())->flatten()->first(),
                ];
            }
        }

        // Once, not per item: a folder of fifteen would otherwise queue
        // fifteen rebuilds of the same index.
        if ($approved > 0) {
            RebuildAssistantIndex::dispatch();
        }

        return response()->json([
            'message'  => $approved . ' approved'
                . ($failed === [] ? '.' : ', ' . count($failed) . ' could not be.'),
            'approved' => $approved,
            'failed'   => $failed,
        ]);
    }

    /**
     * Approve one item and everything that follows from it.
     *
     * Shared by the single button and the folder one, so the two cannot come
     * to mean different things - publishing a version and lifting a module's
     * coming-soon mark are the parts that would quietly go missing from a
     * second copy.
     *
     * @throws ValidationException when a version is not fit to publish
     */
    private function applyApproval(mixed $item, ?User $reviewer, ?string $notes): void
    {
        // Approving a version publishes it, so the readiness check has to run
        // here too - otherwise the rule on publishVersion() is one the approve
        // button walks straight past. Before approveBy(), so a version that
        // cannot go live is not left marked approved either.
        if ($item instanceof RoadmapVersion) {
            (new VersionPublishRules)->assertReadyToPublish($item);
        }

        $item->approveBy($reviewer, $notes);

        // Approving a version is what makes it live, and only one version
        // of a roadmap may be published at a time.
        if ($item instanceof RoadmapVersion) {
            RoadmapVersion::where('roadmap_id', $item->roadmap_id)
                ->where('id', '!=', $item->id)
                ->where('status', 'Published')
                ->update(['status' => 'Archived']);

            $item->update(['status' => 'Published']);
        }

        // Approving a module's material is what makes it teachable, so this is
        // where "coming soon" comes off. Checked after the approval above, and
        // only once the module has all three - a tutorial alone is not a
        // module a student can work through.
        $this->liftComingSoon($item);
    }

    /**
     * Take "coming soon" off a module once its material is approved.
     *
     * The mark says the module was announced before it could be taught. It
     * comes off when the module has an approved tutorial, quiz and project -
     * the same three the publish gate asks for, so a module stops being
     * coming-soon exactly when it would have been publishable anyway.
     *
     * Only material belongs to a module; approving a roadmap or a version has
     * nothing to lift.
     */
    private function liftComingSoon(mixed $item): void
    {
        $versionNodeId = $item->version_node_id ?? null;

        if (! $versionNodeId) {
            return;
        }

        $versionNode = VersionNode::find($versionNodeId);

        if (! $versionNode || ! $versionNode->coming_soon) {
            return;
        }

        $ready = $versionNode->tutorials()->approved()->exists()
            && $versionNode->quizzes()->approved()->exists()
            && $versionNode->projects()->approved()->exists();

        if ($ready) {
            $versionNode->update(['coming_soon' => false]);
        }
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
