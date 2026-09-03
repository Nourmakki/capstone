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
        'committed_version_id',
    ];

    public function roadmapVersion(): BelongsTo
    {
        return $this->belongsTo(RoadmapVersion::class);
    }

    public function committedVersion(): BelongsTo
    {
        return $this->belongsTo(RoadmapVersion::class, 'committed_version_id');
    }

    /**
     * The proposal has been written into a version of ours.
     *
     * Ours to know, not hers: committing happens entirely on this side, and
     * her service goes on reporting the thread as ready_for_commit forever
     * because nothing ever tells it otherwise. Anywhere her status is copied
     * over ours has to check this first.
     */
    public function isCommitted(): bool
    {
        return $this->committed_version_id !== null;
    }
}
