<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\RoadmapVersion;

class Roadmap extends Model
{
    use HasFactory, Reviewable;
    protected $fillable = [
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'title',
        'description',
        'parent_roadmap_id',
    ];

    /** Frontend, Backend and Full Stack sit under Web Development. */
    public function parent()
    {
        return $this->belongsTo(Roadmap::class, 'parent_roadmap_id');
    }

    public function children()
    {
        return $this->hasMany(Roadmap::class, 'parent_roadmap_id');
    }

    /** A subject: a title with roadmaps under it, never a roadmap itself. */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_roadmap_id');
    }

    /**
     * Everything that is a roadmap in its own right.
     *
     * "Web Development", "AI / ML" and "DevOps / Cloud" name a field of
     * study; they are headings over a shelf, and the roadmaps are what sits
     * on it. Counting them alongside Frontend and React overstates what
     * exists and offers a title where a roadmap is meant to be chosen.
     */
    public function scopeRoadmapsOnly($query)
    {
        return $query->whereNotNull('parent_roadmap_id');
    }

    /** A title rather than a roadmap - see {@see scopeRoadmapsOnly}. */
    public function isSubject(): bool
    {
        return $this->parent_roadmap_id === null;
    }

    public function versions()
    {
        return $this->hasMany(RoadmapVersion::class);
    }

    /**
     * Roadmaps a student can actually start.
     *
     * Either it has a published, approved version of its own, or one of its
     * topics does. A roadmap that meets neither leads nowhere, and showing it
     * only lets a student click to discover there is nothing there - so it is
     * withheld until it has something to open.
     *
     * Managers are deliberately not filtered by this: the roadmap they are
     * halfway through building is exactly the one they need to reach.
     */
    /**
     * What a student may see at all, in one place.
     *
     * Two ways in, and they are gated differently on purpose:
     *
     * - Open for real: the roadmap is approved and something live sits behind
     *   it. The rule that has always applied.
     * - Announced: a committed version students may read but not enter. Its
     *   review status is not asked about, because a subject arrives from the
     *   builder with every roadmap in it still pending - and waiting for that
     *   review is exactly the wait announcing it exists to remove.
     *
     * Nothing unapproved is enterable either way: an announced version locks
     * every module, and material is filtered to approved wherever it is read.
     */
    public function scopeStudentVisible($query)
    {
        return $query->where(function ($q) {
            // Live, and every roadmap on the way to it approved - the rule
            // that has always applied, unchanged.
            $q->where(fn ($r) => $r->approved()->whereHas('versions', fn ($v) => $v->live()))
                ->orWhere(fn ($r) => $r->approved()->whereHas(
                    'children',
                    fn ($c) => $c->approved()->whereHas('versions', fn ($v) => $v->live())
                ))
                // Announced, where review status is not asked about.
                ->orWhereHas('versions', fn ($v) => $v->announced())
                ->orWhereHas('children.versions', fn ($v) => $v->announced());
        });
    }

    /** The same question asked of one roadmap rather than a query. */
    public function isStudentVisible(): bool
    {
        return static::whereKey($this->id)->studentVisible()->exists();
    }

    /**
     * Nothing a student can enter yet - only announced versions.
     *
     * Drives the "coming soon" mark on a roadmap in the catalogue, so it can
     * be told apart from one that is genuinely open.
     */
    public function scopeOnlyAnnounced($query)
    {
        return $query
            ->whereDoesntHave('versions', fn ($q) => $q->live())
            ->whereDoesntHave('children.versions', fn ($q) => $q->live());
    }

    /** Announced, with nothing live behind it. */
    public function isOnlyAnnounced(): bool
    {
        return static::whereKey($this->id)->onlyAnnounced()->exists();
    }
}
