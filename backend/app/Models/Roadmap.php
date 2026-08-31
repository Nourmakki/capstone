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

    /** A chooser: it has children instead of modules of its own. */
    public function scopeTopLevel($query)
    {
        return $query->whereNull('parent_roadmap_id');
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
    public function scopeOpenable($query)
    {
        $live = fn ($q) => $q->where('status', 'Published')
            ->where('review_status', 'approved');

        return $query->where(function ($q) use ($live) {
            $q->whereHas('versions', $live)
                ->orWhereHas('children', fn ($child) => $child
                    ->where('review_status', 'approved')
                    ->whereHas('versions', $live));
        });
    }

    /** The same question asked of one roadmap rather than a query. */
    public function isOpenable(): bool
    {
        return static::whereKey($this->id)->openable()->exists();
    }
}
