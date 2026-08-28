<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class NodeLearningWorkflow extends Model
{
    protected $fillable = [
        'version_node_id',
        'thread_id',
        'status',
        'review_stage',
        'committed_at',
    ];

    protected $casts = [
        'committed_at' => 'datetime',
    ];

    public function versionNode(): BelongsTo
    {
        return $this->belongsTo(VersionNode::class);
    }
}