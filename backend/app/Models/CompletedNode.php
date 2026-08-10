<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Node;
use App\Models\UserRoadmapProgress;

class CompletedNode extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_roadmap_progress_id',
        'node_id',
        'completed_at'
    ];

    public function node()
    {
        return $this->belongsTo(Node::class);
    }

    public function userRoadmapProgress()
    {
        return $this->belongsTo(UserRoadmapProgress::class);
    }
}
