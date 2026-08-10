<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Node;
use App\Models\VersionEdge;

class Edge extends Model
{
    use HasFactory;
    protected $fillable = [
        'from_node_id',
        'to_node_id'
    ];

    public function fromNode()
    {
        return $this->belongsTo(Node::class, 'from_node_id');
    }

    public function toNode()
    {
        return $this->belongsTo(Node::class, 'to_node_id');
    }

    public function versionEdges()
    {
        return $this->hasMany(VersionEdge::class);
    }

    public function isSelfLoop()
    {
        return $this->from_node_id == $this->to_node_id;
    }
}
