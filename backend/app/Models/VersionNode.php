<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Node;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\ExternalResource;

class VersionNode extends Model
{
    use HasFactory;
    protected $fillable = [
        'roadmap_version_id',
        'node_id'
    ];

    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class);
    }

    public function node(){
        
        return $this->belongsTo(Node::class);
    }

    public function tutorials()
    {
        return $this->hasMany(Tutorial::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    public function externalResources()
    {
        return $this->hasMany(ExternalResource::class);
    }
}
