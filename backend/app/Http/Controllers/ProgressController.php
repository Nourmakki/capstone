<?php

namespace App\Http\Controllers;

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
            "user_id" => "required|exists:users,id",
            "version_node_id" => "required|exists:version_nodes,id",
        ]);
        
        $student = Student::where(
            "user_id",
            $request->user_id
        )->firstOrFail();

        $versionNode = VersionNode::findOrFail(
            $request->version_node_id
        );
        $progress = UserRoadmapProgress::firstOrCreate(
            [
                "student_id" => $student->id,
                "roadmap_version_id" => $versionNode->roadmap_version_id,
            ],
            [
                "status" => "in_progress",
            ]
        );

        CompletedNode::firstOrCreate([
            "user_roadmap_progress_id" => $progress->id,
            "node_id" => $versionNode->node_id
        ]);

        return response()->json([
            "message" => "Node completed."
        ]);
    }

    public function studentRoadmap($roadmapId, $userId)
{
    $student = Student::where(
        "user_id",
        $userId
    )->firstOrFail();

    $version = RoadmapVersion::where(
        "roadmap_id",
        $roadmapId
    )->latest()->firstOrFail();

    $nodes = VersionNode::with("node")
        ->where(
            "roadmap_version_id",
            $version->id
        )
        ->get();

    $edges = VersionEdge::where(
        "roadmap_version_id",
        $version->id
    )->get();

    $progress = UserRoadmapProgress::where(
        "student_id",
        $student->id
    )
    ->where(
        "roadmap_version_id",
        $version->id
    )
    ->first();

    $completed = [];

    if ($progress) {
        $completed = $progress
            ->completedNodes
            ->pluck("node_id")
            ->toArray();
    }

    $currentNode = $progress?->current_node_id;
    if (!$currentNode) {
        $incomingNodes = $edges->pluck("to_version_node_id");
        $firstNode = $nodes
            ->first(function ($node) use ($incomingNodes) {
                return !$incomingNodes->contains($node->id);
            });
        $currentNode = $firstNode?->id;
    }
    
    return response()->json([
        "version"=>$version,
        "nodes"=>$nodes,
        "edges"=>$edges,
        "completed"=>$completed,
        "current_node"=>$currentNode,
    ]);
}
}