<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the AI system's roadmap builder.
 *
 * A pointer to a LangGraph thread, plus what we asked for and what it became.
 * The run's own state lives in her Postgres checkpoint.
 */
class RoadmapBuilderRun extends Model
{
    protected $fillable = [
        'topic',
        'thread_id',
        'status',
        'review_stage',
        'committed_roadmap_id',
        'committed_at',
        'started_by',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    /** Her statuses that still want a human, as opposed to finished ones. */
    public const ACTIVE_STATUSES = ['awaiting_review', 'ready_for_commit'];

    public function committedRoadmap(): BelongsTo
    {
        return $this->belongsTo(Roadmap::class, 'committed_roadmap_id');
    }

    public function starter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    public function isCommitted(): bool
    {
        return $this->committed_at !== null;
    }
}
