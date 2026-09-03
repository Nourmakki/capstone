<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalResources;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionNode;
use Illuminate\Http\JsonResponse;

/**
 * The platform's catalogue, as passages for the RAG assistant to index.
 *
 * Not aiExport: that returns one version in the exact shape her LangGraph
 * service demands, and it is a graph of ids. This is prose - what a roadmap
 * is, what a module teaches, what comes before what - because an assistant
 * answers from sentences, not adjacency lists.
 *
 * Two rules decide what is in here, and both are about what must never end
 * up in a vector store:
 *
 * 1. Published and approved only. The index is built once and queried by
 *    everyone, so anything unapproved that got in would be quotable by every
 *    student regardless of the rules the API enforces on every other route.
 *
 * 2. Nothing personal, ever. No progress, no scores, no submissions. A vector
 *    store has no idea who is asking; embedding one student's progress makes
 *    it retrievable for another. Personal answers are assembled per request
 *    from live data instead, and never indexed.
 *
 * Quiz answers are deliberately absent for the same reason in a different
 * dress: a student who can ask the assistant what a quiz covers is being
 * helped, and one who can ask it for the answers is being handed them.
 */
class AssistantCorpusController extends Controller
{
    public function index(): JsonResponse
    {
        $passages = collect()
            ->concat($this->subjects())
            ->concat($this->roadmaps())
            ->concat($this->modules())
            ->concat($this->platformConcepts())
            ->values();

        return response()->json([
            'generated_at' => now()->toIso8601String(),
            'count'        => $passages->count(),
            'passages'     => $passages,
        ]);
    }

    /** A passage in the shape the indexer chunks and embeds. */
    private function passage(string $id, string $type, string $title, string $text, array $meta = []): array
    {
        return [
            'id'       => $id,
            'type'     => $type,
            'title'    => $title,
            'text'     => $text,
            'metadata' => $meta + ['type' => $type, 'title' => $title],
        ];
    }

    private function subjects(): array
    {
        $out = [];

        foreach (Roadmap::topLevel()->approved()->with('children')->get() as $subject) {
            $children = $subject->children
                ->where('review_status', 'approved')
                ->pluck('title')
                ->implode(', ');

            if ($children === '') {
                continue;
            }

            $out[] = $this->passage(
                "subject-{$subject->id}",
                'subject',
                $subject->title,
                "{$subject->title} is a subject on the CS Mastery platform. "
                . "{$subject->description} "
                . "It is not a roadmap itself - it groups these roadmaps: {$children}. "
                . 'A student chooses one of them to follow.',
                ['subject' => $subject->title]
            );
        }

        return $out;
    }

    private function roadmaps(): array
    {
        $out = [];

        $live = fn ($q) => $q->where('status', 'Published')->where('review_status', 'approved');

        foreach (Roadmap::roadmapsOnly()->approved()->with(['parent', 'versions' => $live])->get() as $roadmap) {
            $version = $roadmap->versions->first();

            if (! $version) {
                continue;
            }

            $modules = VersionNode::with('node')
                ->where('roadmap_version_id', $version->id)
                ->whereNull('parent_version_node_id')
                ->get();

            $names = $modules->map(fn ($m) => $m->node->title)->implode(', ');

            $out[] = $this->passage(
                "roadmap-{$roadmap->id}",
                'roadmap',
                $roadmap->title,
                "{$roadmap->title} is a roadmap on the CS Mastery platform"
                . ($roadmap->parent ? ", inside the subject {$roadmap->parent->title}" : '')
                . ". {$roadmap->description} "
                . "It contains {$modules->count()} modules, studied in order: {$names}.",
                ['roadmap' => $roadmap->title, 'roadmap_id' => $roadmap->id]
            );
        }

        return $out;
    }

    private function modules(): array
    {
        $out = [];

        $liveVersionIds = RoadmapVersion::where('status', 'Published')
            ->where('review_status', 'approved')
            ->pluck('id');

        $nodes = VersionNode::with(['node', 'roadmapVersion.roadmap'])
            ->whereIn('roadmap_version_id', $liveVersionIds)
            ->get();

        foreach ($nodes as $node) {
            $roadmap = $node->roadmapVersion->roadmap->title ?? 'a roadmap';
            $title   = $node->node->title;

            $text = "{$title} is a module in the {$roadmap} roadmap. "
                . "{$node->node->description} "
                . "Its difficulty is {$node->node->difficulty}.";

            if ($node->coming_soon) {
                $text .= ' It is marked coming soon: it appears on the roadmap but cannot be '
                    . 'entered yet, because its learning material is still being written.';
            }

            $text .= ' ' . $this->materialSentence($node->id);

            $out[] = $this->passage(
                "module-{$node->id}",
                'module',
                $title,
                $text,
                [
                    'roadmap'         => $roadmap,
                    'module'          => $title,
                    'version_node_id' => $node->id,
                    'difficulty'      => $node->node->difficulty,
                ]
            );
        }

        return $out;
    }

    /** What a module carries, named but not reproduced. */
    private function materialSentence(int $versionNodeId): string
    {
        $approved = fn ($query) => $query->where('version_node_id', $versionNodeId)
            ->where('review_status', 'approved');

        $tutorials = $approved(Tutorial::query())->get();
        $quizzes   = $approved(Quiz::query())->get();
        $projects  = $approved(Project::query())->get();
        $resources = $approved(ExternalResources::query())->get();

        $parts = [];

        foreach ($tutorials as $tutorial) {
            $parts[] = "a video tutorial, \"{$tutorial->title}\" from {$tutorial->source_name}";
        }

        foreach ($quizzes as $quiz) {
            // The count, never the questions - and never the answers.
            $parts[] = "a quiz called \"{$quiz->title}\" with {$quiz->questions()->count()} questions";
        }

        foreach ($projects as $project) {
            $parts[] = "a project, \"{$project->title}\": {$project->description}";
        }

        foreach ($resources as $resource) {
            $parts[] = "a reading resource, \"{$resource->title}\"";
        }

        return $parts === []
            ? 'It has no learning material yet.'
            : 'It contains ' . implode('; ', $parts) . '.';
    }

    /**
     * How the platform itself works.
     *
     * Written here rather than read from a table because these are rules in
     * code, not rows - and they are the questions a student most often has
     * that no roadmap can answer.
     */
    private function platformConcepts(): array
    {
        $concepts = [
            'progress' => [
                'How progress and unlocking work',
                'A student works through a roadmap one module at a time. A module unlocks only '
                . 'when every module listed as its prerequisite has been completed. Completing a '
                . 'module means finishing its tutorial, passing its quiz, and submitting its '
                . 'project. Progress is recorded against the version of the roadmap the student '
                . 'started, so later changes to the roadmap do not erase what they have done.',
            ],
            'coming_soon' => [
                'What "coming soon" means on a module',
                'A module marked coming soon has been added to the roadmap but cannot be entered '
                . 'yet. It is shown so students can see what is arriving. It opens once its '
                . 'tutorial, quiz and project have been written by the AI and approved by a '
                . 'material manager. Being coming soon is not the same as being locked: a locked '
                . 'module is one the student has not yet reached, while a coming soon module is '
                . 'waiting on the platform, not on the student.',
            ],
            'choices' => [
                'Choosing between alternatives in a roadmap',
                'Some steps in a roadmap are a choice rather than a single module. Where several '
                . 'tools or frameworks would each serve, the roadmap offers them side by side and '
                . 'the student picks one; studying one of them is enough to continue. One choice '
                . 'may be marked as recommended, with a reason. Some choices open a roadmap of '
                . 'their own rather than a single module.',
            ],
            'review' => [
                'Where the learning material comes from',
                'The learning material on this platform is generated by an AI system and then '
                . 'reviewed by a material manager before students can see it. Nothing reaches a '
                . 'student unapproved. A professor reviews project submissions and can override '
                . 'the score the AI assessment gave.',
            ],
        ];

        $out = [];

        foreach ($concepts as $key => [$title, $text]) {
            $out[] = $this->passage("concept-{$key}", 'concept', $title, $text);
        }

        return $out;
    }
}
