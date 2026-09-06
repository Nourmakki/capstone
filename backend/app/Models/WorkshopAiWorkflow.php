<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One run of the workshop agent.
 *
 * A pointer to her LangGraph thread plus what the platform asked it for. The
 * workshops themselves live in her checkpointer until a manager approves
 * some, at which point they are written onto the version this run names.
 */
class WorkshopAiWorkflow extends Model
{
    protected $fillable = [
        'roadmap_version_id',
        'version_node_id',
        'thread_id',
        'topic',
        'status',
        'review_stage',
        'committed_at',
        'started_by',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    /**
     * Her statuses that still want a human.
     *
     * ready_for_commit is one of them: she has the manager's approval and is
     * holding the approved list, but nothing is on the platform until it is
     * committed here.
     */
    public const ACTIVE_STATUSES = ['awaiting_review', 'ready_for_commit'];

    public function roadmapVersion(): BelongsTo
    {
        return $this->belongsTo(RoadmapVersion::class);
    }

    public function versionNode(): BelongsTo
    {
        return $this->belongsTo(VersionNode::class);
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
