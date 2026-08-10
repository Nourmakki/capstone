<?php

namespace App\Http\Controllers;

use App\Models\VersionNode;
use Illuminate\Http\Request;

class VersionNodeController extends Controller
{
    public function index()
    {
        return VersionNode::all();
    }

    public function store(Request $request)
    {
        return VersionNode::create($request->all());
    }

    public function destroy($id)
    {
        VersionNode::destroy($id);

        return response()->json([
            'message'=>'Version node deleted'
        ]);
    }
}