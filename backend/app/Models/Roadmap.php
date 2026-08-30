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
}
