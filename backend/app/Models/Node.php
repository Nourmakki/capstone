<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Edge;
use App\Models\VersionNode;
use App\Models\CompletedNode;
use App\Models\UserRoadmapProgress;

class Node extends Model
{
    use HasFactory;

    protected $fillable = [
        'title',
        'description',
        'difficulty',
        'status'
    ];

    public function versionNodes()
    {
        return $this->hasMany(VersionNode::class);
    }

    public function roadmapVersions()
    {
        return $this->belongsToMany(
            RoadmapVersion::class,
            'version_nodes'
        );
    }

    public function incomingEdges()
    {
        return $this->hasMany(Edge::class, 'to_node_id');
    }

    public function outgoingEdges()
    {
        return $this->hasMany(Edge::class, 'from_node_id');
    }

    public function completedNodes()
    {
        return $this->hasMany(CompletedNode::class);
    }

    public function userRoadmapProgress()
    {
        return $this->hasMany(UserRoadmapProgress::class);
    }
}