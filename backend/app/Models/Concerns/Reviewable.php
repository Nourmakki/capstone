<?php

namespace App\Models\Concerns;

use App\Models\User;

/**
 * Shared behaviour for anything a material manager approves: roadmaps,
 * roadmap versions, tutorials and external resources.
 */
trait Reviewable
{
    public function reviewer()
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }

    /**
     * Only approved material is visible to students.
     */
    public function scopeApproved($query)
    {
        return $query->where('review_status', 'approved');
    }

    public function scopePendingReview($query)
    {
        return $query->where('review_status', 'pending');
    }

    public function approveBy(User $reviewer, ?string $notes = null): void
    {
        $this->update([
            'review_status' => 'approved',
            'reviewed_by'   => $reviewer->id,
            'reviewed_at'   => now(),
            'review_notes'  => $notes,
        ]);
    }

    public function rejectBy(User $reviewer, string $notes): void
    {
        $this->update([
            'review_status' => 'rejected',
            'reviewed_by'   => $reviewer->id,
            'reviewed_at'   => now(),
            'review_notes'  => $notes,
        ]);
    }

    public function isApproved(): bool
    {
        return $this->review_status === 'approved';
    }
}
