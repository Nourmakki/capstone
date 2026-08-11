<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\Tutorial;
use Illuminate\Http\Request;

class TutorialController extends Controller
{
    public function index($versionNodeId)
{
    $tutorials = Tutorial::where('version_node_id', $versionNodeId)->get();

    return response()->json($tutorials);
}

public function show($id)
{
    $tutorial = Tutorial::with('versionNode')->find($id);

    if (!$tutorial)
    {
        return response()->json([
            'message' => 'Tutorial not found'
        ],404);
    }

    return response()->json($tutorial);
}

public function store(Request $request)
{
    $request->validate([
        'version_node_id' => 'required|exists:version_nodes,id',
        'title' => 'required|string|max:255',
        'url' => 'required|url',
        'description' => 'required|string',
        'source_name' => 'required|string|max:255',
        'difficulty' => 'required|in:beginner,intermediate,advanced',
    ]);

    $tutorial = Tutorial::create($request->all());

    return response()->json([
        'message' => 'Tutorial created successfully',
        'tutorial' => $tutorial
    ],201);
}

public function update(Request $request, $id)
{
    $tutorial = Tutorial::find($id);

    if(!$tutorial)
    {
        return response()->json([
            'message'=>'Tutorial not found'
        ],404);
    }

    $request->validate([
        'version_node_id' => 'required|exists:version_nodes,id',
        'title' => 'required|string|max:255',
        'url' => 'required|url',
        'description' => 'required|string',
        'source_name' => 'required|string|max:255',
        'difficulty' => 'required|in:beginner,intermediate,advanced',
    ]);

    $tutorial->update($request->all());

    return response()->json([
        'message'=>'Tutorial updated successfully',
        'tutorial'=>$tutorial
    ]);
}

public function destroy($id)
{
    $tutorial = Tutorial::find($id);

    if(!$tutorial)
    {
        return response()->json([
            'message'=>'Tutorial not found'
        ],404);
    }

    $tutorial->delete();

    return response()->json([
        'message'=>'Tutorial deleted successfully'
    ]);
}
}
