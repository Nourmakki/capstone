<?php

namespace App\Http\Controllers;

use App\Models\Node;
use Illuminate\Http\Request;

class NodeController extends Controller
{
    public function index()
    {
        return Node::all();
    }

    public function store(Request $request)
    {
        return Node::create($request->all());
    }

    public function show($id)
    {
        return Node::findOrFail($id);
    }

    public function update(Request $request,$id)
    {
        $node=Node::findOrFail($id);

        $node->update($request->all());

        return $node;
    }

    public function destroy($id)
    {
        Node::destroy($id);

        return response()->json([
            'message'=>'Node deleted'
        ]);
    }
}