<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Edge;
use App\Models\RoadmapVersion;

class VersionEdge extends Model
{
    use HasFactory;
    protected $fillable = [
        'roadmap_version_id',
        'edge_id'
    ];

    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class);
    }

    public function edge()
    {
        return $this->belongsTo(Edge::class);
    }

}
