<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\RoadmapVersion;
use Illuminate\Http\Request;

class RoadmapVersionController extends Controller
{
    public function index()
    {
        return RoadmapVersion::all();
    }

    public function store(Request $request)
    {
        return RoadmapVersion::create($request->all());
    }

    public function show($id)
    {
        return RoadmapVersion::findOrFail($id);
    }

    public function update(Request $request,$id)
    {
        $version=RoadmapVersion::findOrFail($id);

        $version->update($request->all());

        return $version;
    }

    public function destroy($id)
    {
        RoadmapVersion::destroy($id);

        return response()->json([
            'message'=>'Version deleted'
        ]);
    }
}