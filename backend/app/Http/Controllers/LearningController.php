<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use App\Models\UserRoadmapProgress;
use App\Models\CompletedNode;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;

class LearningController extends Controller
{
    public function dashboard($studentId)
    {

        $progress = UserRoadmapProgress::where(
            'student_id',
            $studentId
        )->get();

        $result = [];

        foreach($progress as $item){

            $roadmap = Roadmap::find($item->roadmap_id);

            $version = RoadmapVersion::find(
                $item->roadmap_version_id
            );

            $completedNodes = CompletedNode::where(
                'student_id',
                $studentId
            )
            ->where(
                'roadmap_version_id',
                $version->id
            )
            ->count();

            $totalNodes = VersionNode::where(
                'roadmap_version_id',
                $version->id
            )->count();

            $percentage = 0;

            if($totalNodes>0){
                $percentage = round(
                    ($completedNodes/$totalNodes)*100
                );
            }

            $result[] = [

                "roadmap"=>$roadmap,

                "version"=>$version,

                "completed_nodes"=>$completedNodes,

                "total_nodes"=>$totalNodes,

                "progress"=>$percentage

            ];

        }

        return response()->json($result);

    }

    public function continueLearning($studentId)
{

    $learning = UserRoadmapProgress::where(
        'student_id',
        $studentId
    )
    ->orderByDesc('updated_at')
    ->first();

    if(!$learning){

        return response()->json([
            "message"=>"No roadmap started."
        ],404);

    }

    return response()->json($learning);

}

public function completedNodes($studentId,$versionId)
{

    return CompletedNode::where(
        'student_id',
        $studentId
    )
    ->where(
        'roadmap_version_id',
        $versionId
    )
    ->get();

}

public function statistics($studentId)
{

    $roadmaps = UserRoadmapProgress::where(
        'student_id',
        $studentId
    )->count();

    $completedNodes = CompletedNode::where(
        'student_id',
        $studentId
    )->count();

    return response()->json([

        "roadmaps"=>$roadmaps,

        "completed_nodes"=>$completedNodes

    ]);

}

}