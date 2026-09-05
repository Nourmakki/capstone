<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadmapVersion;
use App\Models\Student;
use App\Models\Submission;
use App\Models\UserRoadmapProgress;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Http\Request;

class LearningController extends Controller
{
    /**
     * Everything LearningPage needs for one module.
     */
    public function nodeDetail(Request $request, VersionNode $versionNode)
    {
        // A module of an announced version is not enterable. The roadmap page
        // draws these locked, but locking in the page alone is decoration -
        // this endpoint takes a version_node_id and, without the check, opened
        // any module of any version to anyone holding the URL.
        //
        // Staff still reach it: reviewing material means opening the module it
        // is on, and that is exactly a version not published yet.
        if (
            $versionNode->roadmapVersion?->isAnnounced()
            && ! in_array($request->user()?->role, ['MaterialManager', 'Professor', 'Admin'], true)
        ) {
            abort(403, 'This module is coming soon. Its material is still being written.');
        }

        $versionNode->load([
            'node',
            // Only material a manager approved reaches students.
            'tutorials' => fn ($q) => $q->approved(),
            'quizzes' => fn ($q) => $q->approved(),
            'quizzes.questions',
            'projects' => fn ($q) => $q->approved(),
            'externalResources' => fn ($q) => $q->approved(),
            'roadmapVersion.roadmap',
        ]);

        $completed = $this->completedNodeIds($request, $versionNode->roadmap_version_id);

        // Prerequisites come from the edges pointing at this node.
        $prerequisiteIds = VersionEdge::with('edge')
            ->where('roadmap_version_id', $versionNode->roadmap_version_id)
            ->get()
            ->filter(fn ($ve) => $ve->edge && $ve->edge->to_node_id === $versionNode->node_id)
            ->map(fn ($ve) => $ve->edge->from_node_id)
            ->values();

        $prerequisites = VersionNode::with('node')
            ->where('roadmap_version_id', $versionNode->roadmap_version_id)
            ->whereIn('node_id', $prerequisiteIds)
            ->get()
            ->map(fn ($vn) => [
                'version_node_id' => $vn->id,
                'node_id'         => $vn->node_id,
                'title'           => $vn->node->title,
                'completed'       => in_array($vn->node_id, $completed, true),
            ]);

        return response()->json([
            'version_node_id' => $versionNode->id,
            'node'            => [
                'id'          => $versionNode->node->id,
                'title'       => $versionNode->node->title,
                'description' => $versionNode->node->description,
                'difficulty'  => $versionNode->node->difficulty,
            ],
            'roadmap' => [
                'id'    => $versionNode->roadmapVersion->roadmap->id,
                'title' => $versionNode->roadmapVersion->roadmap->title,
            ],
            'version' => [
                'id'             => $versionNode->roadmapVersion->id,
                'version_number' => $versionNode->roadmapVersion->version_number,
            ],
            'tutorials'          => $this->tutorialsWithProgress($request, $versionNode),
            'quizzes'            => $this->quizzesWithProgress($request, $versionNode),
            'projects'           => $this->projectsWithProgress($request, $versionNode),
            'external_resources' => $versionNode->externalResources,
            'prerequisites'      => $prerequisites,
            'is_completed'       => in_array($versionNode->node_id, $completed, true),
            // Announced before its material was written. Shown on the rail so
            // a student can see what is coming, but there is nothing here to
            // do yet - so it is not unlocked however far they have got.
            'coming_soon'        => $versionNode->coming_soon,
            'is_unlocked'        => ! $versionNode->coming_soon
                && $prerequisites->every(fn ($p) => $p['completed']),
            // What still has to be done before this module can be completed,
            // so the button can explain itself instead of failing on click.
            'completion_blockers' => $this->blockersFor($request, $versionNode),
        ]);
    }

    /**
     * Where the student left off - drives ContinueLearningPage.
     */
    public function continueLearning(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        // Every roadmap the student has touched, not only the last one. A
        // student working through two at once could see only whichever they
        // opened most recently, and the other was reachable solely by
        // remembering it existed and finding it on the dashboard.
        $rows = $student
            ? UserRoadmapProgress::with('roadmapVersion.roadmap.parent')
                ->where('student_id', $student->id)
                ->orderByDesc('updated_at')
                ->get()
            : collect();

        if ($rows->isEmpty()) {
            return response()->json(['has_progress' => false, 'roadmaps' => []]);
        }

        return response()->json([
            'has_progress' => true,
            // Most recent first: that one is "carry on where you left off",
            // and the rest are the others still open.
            'roadmaps'     => $rows->map(fn ($p) => $this->progressSummary($p))->values(),
        ]);
    }

    /** One roadmap a student is partway through. */
    private function progressSummary(UserRoadmapProgress $progress): array
    {
        $version = $progress->roadmapVersion;

        // Across every version of this roadmap - see
        // UserRoadmapProgress::completedNodeIdsFor.
        $completed = $progress->completedNodeIds();

        $nodes = VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->get();

        $edges = VersionEdge::with('edge')
            ->where('roadmap_version_id', $version->id)
            ->get()
            ->filter(fn ($ve) => $ve->edge !== null);

        $prerequisites = [];
        foreach ($edges as $versionEdge) {
            $prerequisites[$versionEdge->edge->to_node_id][] = $versionEdge->edge->from_node_id;
        }

        $current = null;
        foreach ($nodes as $versionNode) {
            if (in_array($versionNode->node_id, $completed, true)) {
                continue;
            }

            $required = $prerequisites[$versionNode->node_id] ?? [];

            if (empty(array_diff($required, $completed))) {
                $current = $versionNode;
                break;
            }
        }

        $total = $nodes->count();

        // Progress pins a student to the version they started, which is right -
        // their completions belong to that graph. But nothing here ever said so,
        // and once a newer version was published this page went on serving the
        // old one with no sign that it had. A module renamed or removed since
        // simply looked like the roadmap.
        $published = RoadmapVersion::where('roadmap_id', $version->roadmap_id)
            ->where('status', 'Published')
            ->orderByDesc('id')
            ->first();

        $isLatest = $published === null || $published->id === $version->id;

        return [
            'roadmap'      => [
                'id'    => $version->roadmap->id,
                'title' => $version->roadmap->title,
                // Where this sits, so "React" does not arrive without saying
                // which roadmap it came out of.
                'parent' => $version->roadmap->parent
                    ? ['id' => $version->roadmap->parent->id, 'title' => $version->roadmap->parent->title]
                    : null,
            ],
            'version' => [
                'id'             => $version->id,
                'version_number' => $version->version_number,
                'status'         => $version->status,
            ],
            'is_latest'      => $isLatest,
            'newer_version'  => $isLatest ? null : [
                'id'             => $published->id,
                'version_number' => $published->version_number,
            ],
            'current_node' => $current ? [
                'version_node_id' => $current->id,
                'node_id'         => $current->node_id,
                'title'           => $current->node->title,
                'description'     => $current->node->description,
            ] : null,
            'completed_count'  => count($completed),
            'total_nodes'      => $total,
            'percent_complete' => $total > 0
                ? (int) round((count($completed) / $total) * 100)
                : 0,
            // A roadmap with no module left to unlock is finished, which reads
            // differently from one merely at 100%.
            'is_finished'      => $current === null,
            'last_active'      => $progress->updated_at,
        ];
    }

    /**
     * The module's tutorials, each carrying whether this student has marked it
     * watched, so the button knows which state to render.
     *
     * Staff have no student record; for them nothing is marked, and the page
     * shows the tutorials without progress rather than failing.
     */
    /**
     * Each quiz on this module, and whether this student has passed it.
     *
     * The page had nothing to go on and fell back to "a quiz exists", which
     * ticked the moment a manager approved one - telling a student they had
     * passed a quiz they had never opened. Passing is the same test
     * completionBlockers applies, so the tick and the list of what is left
     * cannot contradict each other.
     */
    private function quizzesWithProgress(Request $request, VersionNode $versionNode)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        return $versionNode->quizzes->map(function ($quiz) use ($student) {
            $best = $student
                ? Submission::where('quiz_id', $quiz->id)
                    ->where('student_id', $student->id)
                    ->max('score')
                : null;

            return $quiz->toArray() + [
                'attempted'  => $best !== null,
                'best_score' => $best,
                'pass_mark'  => QuizController::PASS_MARK,
                'passed'     => $best !== null && $best >= QuizController::PASS_MARK,
            ];
        });
    }

    /**
     * Each project, and whether this student has handed anything in.
     *
     * Submitting is the bar, not being graded - completionBlockers asks only
     * that something was handed in, and a tick that waited for a score would
     * leave a student unable to finish a module until somebody marked it.
     */
    private function projectsWithProgress(Request $request, VersionNode $versionNode)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        return $versionNode->projects->map(function ($project) use ($student) {
            $submission = $student
                ? Submission::where('project_id', $project->id)
                    ->where('student_id', $student->id)
                    ->latest('id')
                    ->first()
                : null;

            return $project->toArray() + [
                'submitted'   => $submission !== null,
                'final_score' => $submission?->finalScore(),
            ];
        });
    }

    private function tutorialsWithProgress(Request $request, VersionNode $versionNode)
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        $watched = $student
            ? ProgressController::watchedTutorialIds($versionNode->tutorials, $student)
            : [];

        return $versionNode->tutorials->map(fn ($tutorial) => [
            'id'              => $tutorial->id,
            'version_node_id' => $tutorial->version_node_id,
            'title'           => $tutorial->title,
            'url'             => $tutorial->url,
            'description'     => $tutorial->description,
            'source_name'     => $tutorial->source_name,
            'completed'       => in_array($tutorial->id, $watched, true),
        ]);
    }

    /**
     * Staff have no student record, so nothing blocks them.
     *
     * @return array<int, string>
     */
    private function blockersFor(Request $request, VersionNode $versionNode): array
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return [];
        }

        $progress = UserRoadmapProgress::where('student_id', $student->id)
            ->where('roadmap_version_id', $versionNode->roadmap_version_id)
            ->first();

        return ProgressController::completionBlockers($versionNode, $student, $progress);
    }

    private function completedNodeIds(Request $request, int $versionId): array
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return [];
        }

        // Asked of the roadmap rather than the one version, so a module
        // finished before this version was published still reads as finished
        // - see UserRoadmapProgress::completedNodeIdsFor. Looked up from the
        // version because that is all the caller has.
        $roadmapId = RoadmapVersion::where('id', $versionId)->value('roadmap_id');

        return UserRoadmapProgress::completedNodeIdsFor($student->id, $roadmapId);
    }
}