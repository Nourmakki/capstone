<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use App\Models\RoadmapVersion;

class RoadmapAiWorkflow extends Model
{
    protected $fillable = [
        'roadmap_version_id',
        'thread_id',
        'status',
        'review_stage',
    ];

    public function roadmapVersion(): BelongsTo
    {
        return $this->belongsTo(RoadmapVersion::class);
    }
}
