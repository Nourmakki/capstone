<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;
use App\Models\RoadmapVersion;
use App\Models\CompletedNode;
use App\Models\Node;

class UserRoadmapProgress extends Model
{
    use HasFactory;
    protected $fillable = [
    'student_id',
    'roadmap_version_id',
    'current_node_id',
    'status'
];
    public function student()
    {
        return $this->belongsTo(Student::class, 'student_id');
    }
    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class, 'roadmap_version_id');
    }
    public function currentNode()
    {
        return $this->belongsTo(Node::class, 'current_node_id');
    }

    public function completedNodes()
    {
        return $this->hasMany(CompletedNode::class);
    }
}
