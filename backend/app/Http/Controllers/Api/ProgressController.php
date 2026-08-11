<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\CompletedNode;
use App\Models\UserRoadmapProgress;
use App\Models\Student;
use Illuminate\Http\Request;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use App\Models\VersionEdge;

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

        public function studentRoadmap($roadmapId, $userId)
    {
        $student = Student::where('user_id', $userId)->firstOrFail();

        $version = RoadmapVersion::where('roadmap_id', $roadmapId)
            ->where('status', 'Published')
            ->orderByDesc('version_number')
            ->firstOrFail();

        $nodes = VersionNode::with('node')
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

        return response()->json([
            'version'      => $version,
            'nodes'        => $nodes,
            'edges'        => $edges,
            'completed'    => $completed,
            'current_node' => $currentNode,
        ]);
    }
}