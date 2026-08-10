<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Roadmap;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Models\UserRoadmapProgress;
use App\Models\Workshop;
use App\Models\ExternalResources;



class RoadmapVersion extends Model
{
    use HasFactory;
    protected $fillable = [
        'roadmap_id',
        'version_number',
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


}
