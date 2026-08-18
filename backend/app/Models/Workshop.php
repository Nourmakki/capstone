<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;

class Workshop extends Model
{
    use HasFactory;

    protected $fillable = [
        'roadmap_version_id',
        'version_node_id',
        'title',
        'description',
        'scheduled_at',
        'duration_minutes',
        'location',
        'country',
        'url',
    ];

    protected $casts = [
        'scheduled_at'     => 'datetime',
        'duration_minutes' => 'integer',
    ];

    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class);
    }

    public function versionNode()
    {
        return $this->belongsTo(VersionNode::class);
    }
}