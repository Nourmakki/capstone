<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Roadmap;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Models\UserRoadmapProgress;
use App\Models\Workshop;
use App\Models\ExternalResources;
use App\Models\RoadmapAiWorkflow;


class RoadmapVersion extends Model
{
    use HasFactory, Reviewable;
    protected $fillable = [
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'roadmap_id',
        'version_number',
        'title',
        // 'is_active',
        'status',
        'announced',
    ];

    protected $casts = [
        'announced' => 'boolean',
    ];

    /**
     * Readable by a student, but not enterable.
     *
     * A committed version whose modules have no material yet. It appears as
     * coming soon so the roadmap's shape can be seen, and every module in it
     * stays locked until the version is published for real.
     */
    public function isAnnounced(): bool
    {
        return $this->announced && $this->status === 'Draft';
    }

    /** Live: what a student can actually work through. */
    public function scopeLive($query)
    {
        return $query->where('status', 'Published')->where('review_status', 'approved');
    }

    /**
     * Announced: readable, locked, waiting on its material.
     *
     * Draft specifically, not "anything unpublished". Archived is a version
     * that has been superseded, and one that was announced on its way up
     * should not come back as a preview on its way down.
     */
    public function scopeAnnounced($query)
    {
        return $query->where('announced', true)->where('status', 'Draft');
    }

    public function roadmap()
    {
        return $this->belongsTo(Roadmap::class);
    }

    public function versionNodes()
    {
        return $this->hasMany(VersionNode::class);
    }

    public function versionEdges()
    {
        return $this->hasMany(VersionEdge::class);
    }

    public function roadmapProgress()
    {
        return $this->hasMany(UserRoadmapProgress::class);
    }

    public function workshops()
    {
        return $this->hasMany(Workshop::class);
    }

    public function externalResources()
    {
        return $this->hasMany(ExternalResources::class);
    }

    public function aiWorkflows()
    {
        return $this->hasMany(RoadmapAiWorkflow::class);
    }


}
