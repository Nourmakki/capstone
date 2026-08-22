<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Roadmap;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Models\UserRoadmapProgress;
use App\Models\Workshop;
use App\Models\ExternalResources;
use App\Models\RoadmapAiWorkflow;


class RoadmapVersion extends Model
{
    use HasFactory, Reviewable;
    protected $fillable = [
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'roadmap_id',
        'version_number',
        'title',
        // 'is_active',
        'status'
    ];

    public function roadmap()
    {
        return $this->belongsTo(Roadmap::class);
    }

    public function versionNodes()
    {
        return $this->hasMany(VersionNode::class);
    }

    public function versionEdges()
    {
        return $this->hasMany(VersionEdge::class);
    }

    public function roadmapProgress()
    {
        return $this->hasMany(UserRoadmapProgress::class);
    }

    public function workshops()
    {
        return $this->hasMany(Workshop::class);
    }

    public function externalResources()
    {
        return $this->hasMany(ExternalResources::class);
    }

    public function aiWorkflows()
    {
        return $this->hasMany(RoadmapAiWorkflow::class);
    }


}
