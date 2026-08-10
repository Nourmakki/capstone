<?php

namespace App\Http\Controllers;

use App\Models\Edge;
use Illuminate\Http\Request;

class EdgeController extends Controller
{
    public function index()
    {
        return Edge::all();
    }

    public function store(Request $request)
    {
        return Edge::create($request->all());
    }

    public function destroy($id)
    {
        Edge::destroy($id);

        return response()->json([
            'message'=>'Edge deleted'
        ]);
    }
}