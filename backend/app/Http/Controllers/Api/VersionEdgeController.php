<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;

use App\Models\VersionEdge;
use Illuminate\Http\Request;

class VersionEdgeController extends Controller
{
    public function index()
    {
        return VersionEdge::all();
    }

    public function store(Request $request)
    {
        return VersionEdge::create($request->all());
    }

    public function destroy($id)
    {
        VersionEdge::destroy($id);

        return response()->json([
            'message'=>'Version edge deleted'
        ]);
    }
}