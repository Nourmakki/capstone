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
        ] + $this->clearedChangeNote());
    }

    public function rejectBy(User $reviewer, string $notes): void
    {
        $this->update([
            'review_status' => 'rejected',
            'reviewed_by'   => $reviewer->id,
            'reviewed_at'   => now(),
            'review_notes'  => $notes,
        ] + $this->clearedChangeNote());
    }

    /**
     * Send approved material back to the queue, saying what changed.
     *
     * Approved content that has been edited has not been approved in its new
     * form. The note is what tells the reviewer where to look - without it the
     * item reappears in the queue indistinguishable from a new one.
     */
    public function sendBackForReview(?string $changeNote = null): void
    {
        $update = [
            'review_status' => 'pending',
            'reviewed_by'   => null,
            'reviewed_at'   => null,
            'review_notes'  => null,
        ];

        if ($this->tracksChangeNotes()) {
            $update['change_note'] = $changeNote;
        }

        $this->update($update);
    }

    /**
     * Only the three types a manager can edit carry the column; roadmaps and
     * versions are reviewed but not edited field by field.
     */
    private function tracksChangeNotes(): bool
    {
        return in_array('change_note', $this->getFillable(), true);
    }

    /** @return array<string,null> */
    private function clearedChangeNote(): array
    {
        // The note describes an edit awaiting a decision. Once the decision is
        // made it is history, and leaving it would label the item forever.
        return $this->tracksChangeNotes() ? ['change_note' => null] : [];
    }

    public function isApproved(): bool
    {
        return $this->review_status === 'approved';
    }
}
