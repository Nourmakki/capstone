<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\RoadmapVersionResource;

use App\Models\CompletedNode;
use App\Models\UserRoadmapProgress;
use App\Models\Student;
use Illuminate\Http\Request;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\TutorialCompletion;
use App\Models\VersionNode;
use App\Models\VersionEdge;
use App\Models\Submission;

class ProgressController extends Controller
{
    public function completeNode(Request $request)
    {
        $request->validate([
            'version_node_id' => ['required', 'exists:version_nodes,id'],
        ]);

        // Trust the token, not a user_id in the body.
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        $versionNode = VersionNode::findOrFail($request->version_node_id);

        $progress = UserRoadmapProgress::firstOrCreate(
            [
                'student_id'         => $student->id,
                'roadmap_version_id' => $versionNode->roadmap_version_id,
            ],
            ['status' => 'in_progress']
        );

        // A module is only finished once its prerequisites, quizzes and
        // projects are done - otherwise progress means nothing.
        $blockers = $this->completionBlockers($versionNode, $student, $progress);

        if ($blockers !== []) {
            return response()->json([
                'message'  => 'This module is not finished yet.',
                'blockers' => $blockers,
            ], 422);
        }

        CompletedNode::firstOrCreate([
            'user_roadmap_progress_id' => $progress->id,
            'node_id'                  => $versionNode->node_id,
        ]);

        $completed = $progress->completedNodes()->pluck('node_id')->toArray();

        $total = VersionNode::where('roadmap_version_id', $versionNode->roadmap_version_id)->count();

        $progress->update([
            'current_node_id' => $versionNode->node_id,
            'status'          => count($completed) >= $total ? 'completed' : 'in_progress',
        ]);

        return response()->json([
            'message'          => 'Node completed.',
            'completed_count'  => count($completed),
            'total_nodes'      => $total,
            'percent_complete' => $total > 0 ? (int) round((count($completed) / $total) * 100) : 0,
        ]);
    }

    /**
     * Everything standing between a student and completing this module.
     * Returns an empty array when they are free to finish it.
     *
     * @return array<int, string>
     */
    /**
     * The mandatory work on one module and how much of it is done.
     *
     * Counts the same things completionBlockers() refuses on - every quiz
     * passed, every project submitted - so the two can never disagree about
     * whether a module is finished.
     *
     * @return array{done:int, total:int}
     */
    public static function requirementProgress(VersionNode $versionNode, Student $student): array
    {
        $total = 0;
        $done  = 0;

        $tutorials = self::approvedTutorials($versionNode);
        $watched   = self::watchedTutorialIds($tutorials, $student);

        foreach ($tutorials as $tutorial) {
            $total++;

            if (in_array($tutorial->id, $watched, true)) {
                $done++;
            }
        }

        foreach ($versionNode->quizzes as $quiz) {
            $total++;

            $best = Submission::where('quiz_id', $quiz->id)
                ->where('student_id', $student->id)
                ->max('score');

            if ($best !== null && $best >= QuizController::PASS_MARK) {
                $done++;
            }
        }

        foreach ($versionNode->projects as $project) {
            $total++;

            $submitted = Submission::where('project_id', $project->id)
                ->where('student_id', $student->id)
                ->exists();

            if ($submitted) {
                $done++;
            }
        }

        return ['done' => $done, 'total' => $total];
    }

    /**
     * A student says they have watched a tutorial.
     *
     * Idempotent: pressing it twice is the same fact, and a duplicate would
     * only trip the unique index.
     */
    public function completeTutorial(Request $request, Tutorial $tutorial)
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        // An unapproved tutorial is not visible to a student, so it is not
        // theirs to mark either.
        if (! $tutorial->isApproved()) {
            return response()->json(['message' => 'That tutorial is not available.'], 404);
        }

        TutorialCompletion::firstOrCreate([
            'student_id'  => $student->id,
            'tutorial_id' => $tutorial->id,
        ]);

        return response()->json(['message' => 'Marked as done.', 'completed' => true]);
    }

    /**
     * Undo. A misclick should not leave a module permanently claiming work the
     * student has not done.
     */
    public function uncompleteTutorial(Request $request, Tutorial $tutorial)
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        TutorialCompletion::where('student_id', $student->id)
            ->where('tutorial_id', $tutorial->id)
            ->delete();

        return response()->json(['message' => 'Marked as not done.', 'completed' => false]);
    }

    /**
     * Only approved tutorials count.
     *
     * Queried rather than read off the relation because completionBlockers is
     * reached two ways: LearningController pre-loads tutorials already filtered
     * to approved, while completeNode loads the node bare. Reading
     * $versionNode->tutorials would mean the two disagreed, and a student could
     * be blocked by a tutorial their page never showed them.
     */
    private static function approvedTutorials(VersionNode $versionNode)
    {
        return Tutorial::where('version_node_id', $versionNode->id)->approved()->get();
    }

    /**
     * @param  \Illuminate\Support\Collection<int,Tutorial> $tutorials
     * @return array<int,int>
     */
    public static function watchedTutorialIds($tutorials, Student $student): array
    {
        if ($tutorials->isEmpty()) {
            return [];
        }

        return TutorialCompletion::where('student_id', $student->id)
            ->whereIn('tutorial_id', $tutorials->pluck('id'))
            ->pluck('tutorial_id')
            ->all();
    }

    /**
     * The modules that come before this one, in the shape the AI service
     * expects, so generated material does not repeat earlier ground.
     */
    public static function prerequisiteNodesFor(VersionNode $versionNode): array
    {
        $prerequisiteIds = VersionEdge::with('edge')
            ->where('roadmap_version_id', $versionNode->roadmap_version_id)
            ->get()
            ->filter(fn ($ve) => $ve->edge && $ve->edge->to_node_id === $versionNode->node_id)
            ->map(fn ($ve) => $ve->edge->from_node_id)
            ->values();

        return VersionNode::with('node')
            ->where('roadmap_version_id', $versionNode->roadmap_version_id)
            ->whereIn('node_id', $prerequisiteIds)
            ->get()
            ->map(fn ($vn) => [
                'id'          => $vn->node_id,
                'title'       => $vn->node->title,
                'description' => $vn->node->description,
                'difficulty'  => $vn->node->difficulty,
            ])
            ->values()
            ->all();
    }

    public static function completionBlockers(
        VersionNode $versionNode,
        Student $student,
        ?UserRoadmapProgress $progress
    ): array {
        $blockers = [];

        $completed = $progress
            ? $progress->completedNodes()->pluck('node_id')->toArray()
            : [];

        // 1. Prerequisites, taken from the edges pointing at this node.
        $prerequisiteIds = VersionEdge::with('edge')
            ->where('roadmap_version_id', $versionNode->roadmap_version_id)
            ->get()
            ->filter(fn ($ve) => $ve->edge && $ve->edge->to_node_id === $versionNode->node_id)
            ->map(fn ($ve) => $ve->edge->from_node_id)
            ->values();

        $missing = $prerequisiteIds->reject(fn ($id) => in_array($id, $completed, true));

        if ($missing->isNotEmpty()) {
            $titles = VersionNode::with('node')
                ->where('roadmap_version_id', $versionNode->roadmap_version_id)
                ->whereIn('node_id', $missing)
                ->get()
                ->map(fn ($vn) => $vn->node->title)
                ->implode(', ');

            $blockers[] = "Finish the earlier module(s) first: {$titles}.";
        }

        // 2. Every tutorial has to be watched and marked. Nothing can verify a
        // video was actually watched, so this is the student's own word for
        // it - which is still more than the nothing that was there before.
        $tutorials = self::approvedTutorials($versionNode);
        $watched   = self::watchedTutorialIds($tutorials, $student);

        foreach ($tutorials as $tutorial) {
            if (! in_array($tutorial->id, $watched, true)) {
                $blockers[] = "Watch \"{$tutorial->title}\" and mark it done.";
            }
        }

        // 3. Every quiz on this module must be passed.
        foreach ($versionNode->quizzes as $quiz) {
            $best = Submission::where('quiz_id', $quiz->id)
                ->where('student_id', $student->id)
                ->max('score');

            if ($best === null) {
                $blockers[] = "Take the quiz \"{$quiz->title}\".";
            } elseif ($best < QuizController::PASS_MARK) {
                $blockers[] = "Pass \"{$quiz->title}\" - your best score is {$best}%, "
                    . QuizController::PASS_MARK . "% is needed.";
            }
        }

        // 3. Every project on this module must have been submitted.
        foreach ($versionNode->projects as $project) {
            $submitted = Submission::where('project_id', $project->id)
                ->where('student_id', $student->id)
                ->exists();

            if (! $submitted) {
                $blockers[] = "Submit the project \"{$project->title}\".";
            }
        }

        return $blockers;
    }

        public function studentRoadmap(Request $request, $roadmapId, $userId)
    {
        $student = Student::where('user_id', $userId)->firstOrFail();

        // Every version a student may read: published and manager-approved.
        $availableVersions = RoadmapVersion::where('roadmap_id', $roadmapId)
            ->whereIn('status', ['Published', 'Archived'])
            ->approved()
            ->orderByDesc('id')
            ->get();

        abort_if($availableVersions->isEmpty(), 404, 'This roadmap has no readable version.');

        // A student may pin an older version; otherwise show the live one.
        $requested = $request->query('version');

        $version = $requested
            ? $availableVersions->firstWhere('id', (int) $requested)
            : $availableVersions->firstWhere('status', 'Published');

        // An unknown or unreadable id falls back rather than 404ing.
        $version ??= $availableVersions->first();

        $nodes = VersionNode::with(['node', 'quizzes', 'projects'])
            ->where('roadmap_version_id', $version->id)
            ->get();

        // version_edges only stores edge_id, so load the edge for from/to.
        $edges = VersionEdge::with('edge')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->filter(fn ($ve) => $ve->edge !== null)
            ->map(fn ($ve) => [
                'from_node_id' => $ve->edge->from_node_id,
                'to_node_id'   => $ve->edge->to_node_id,
            ])
            ->values();

        $progress = UserRoadmapProgress::where('student_id', $student->id)
            ->where('roadmap_version_id', $version->id)
            ->first();

        $completed = $progress
            ? $progress->completedNodes->pluck('node_id')->toArray()
            : [];

        // A node is unlocked when every prerequisite is completed.
        $prerequisites = [];
        foreach ($edges as $edge) {
            $prerequisites[$edge['to_node_id']][] = $edge['from_node_id'];
        }

        $currentNode = null;
        foreach ($nodes as $versionNode) {
            $nodeId = $versionNode->node_id;

            if (in_array($nodeId, $completed, true)) {
                continue;
            }

            $required = $prerequisites[$nodeId] ?? [];
            $unlocked = empty(array_diff($required, $completed));

            if ($unlocked) {
                $currentNode = $nodeId;
                break;
            }
        }

        // How far through each module's mandatory work the student is, so the
        // roadmap can show partial progress rather than only done/not done.
        $requirements = [];

        foreach ($nodes as $versionNode) {
            $requirements[$versionNode->node_id] = self::requirementProgress($versionNode, $student);
        }

        return response()->json([
            'version'            => new RoadmapVersionResource($version),
            'is_latest'          => $version->status === 'Published',
            'available_versions' => RoadmapVersionResource::collection($availableVersions),
            'nodes'              => $nodes,
            'requirements'       => $requirements,
            'edges'              => $edges,
            'completed'          => $completed,
            'current_node'       => $currentNode,
        ]);
    }
}