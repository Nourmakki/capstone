<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Student;
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
            'tutorials'          => $versionNode->tutorials,
            'quizzes'            => $versionNode->quizzes,
            'projects'           => $versionNode->projects,
            'external_resources' => $versionNode->externalResources,
            'prerequisites'      => $prerequisites,
            'is_completed'       => in_array($versionNode->node_id, $completed, true),
            'is_unlocked'        => $prerequisites->every(fn ($p) => $p['completed']),
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

        $progress = $student
            ? UserRoadmapProgress::with('roadmapVersion.roadmap')
                ->where('student_id', $student->id)
                ->orderByDesc('updated_at')
                ->first()
            : null;

        if (! $progress) {
            return response()->json(['has_progress' => false]);
        }

        $version   = $progress->roadmapVersion;
        $completed = $progress->completedNodes->pluck('node_id')->toArray();

        $nodes = VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
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

        return response()->json([
            'has_progress' => true,
            'roadmap'      => [
                'id'    => $version->roadmap->id,
                'title' => $version->roadmap->title,
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

        $progress = UserRoadmapProgress::where('student_id', $student->id)
            ->where('roadmap_version_id', $versionId)
            ->first();

        return $progress
            ? $progress->completedNodes->pluck('node_id')->toArray()
            : [];
    }
}