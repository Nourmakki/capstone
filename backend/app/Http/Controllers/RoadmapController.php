<?php

namespace App\Http\Controllers;

use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use App\Models\VersionEdge;
use Illuminate\Http\Request;

class RoadmapController extends Controller
{
    public function index()
    {
        return response()->json(
            Roadmap::all()
        );
    }


    public function show($id)
    {
        return response()->json(
            Roadmap::findOrFail($id)
        );
    }

    public function latestVersion($roadmapId)
    {
        $version = RoadmapVersion::where(
            "roadmap_id",
            $roadmapId
        )
        ->latest()
        ->first();

        return response()->json($version);
    }

    public function graph($versionId)
    {
        $nodes = VersionNode::with("node")
            ->where("roadmap_version_id", $versionId)
            ->get();

        $edges = VersionEdge::where(
            "roadmap_version_id",
            $versionId
        )->get();

        return response()->json([
            "nodes"=>$nodes,
            "edges"=>$edges,
        ]);
    }

    public function store(Request $request)
    {
        $request->validate([
            "title"=>"required",
            "description"=>"required"
        ]);

        $roadmap = Roadmap::create([
            "title"=>$request->title,
            "description"=>$request->description,
        ]);

        return response()->json($roadmap,201);
    }

    public function update(Request $request,$id)
    {
        $roadmap = Roadmap::findOrFail($id);

        $roadmap->update($request->all());

        return response()->json($roadmap);
    }


    public function destroy($id)
    {
        Roadmap::destroy($id);

        return response()->json([
            "message"=>"Roadmap deleted."
        ]);
    }
}