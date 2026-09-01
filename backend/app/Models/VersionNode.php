<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Node;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\ExternalResources;

class VersionNode extends Model
{
    use HasFactory;
    protected $fillable = [
        'roadmap_version_id',
        'node_id',
        'parent_version_node_id',
        'links_to_roadmap_id',
        'stage',
        'stage_title',
        'is_recommended',
        'recommendation_reason',
    ];

    protected $casts = [
        'is_recommended' => 'boolean',
    ];

    /**
     * The node this one is a choice of.
     *
     * A module in the rail has none. "HTML" under "Fundamentals" has
     * Fundamentals, and appears as a button on it rather than as its own step.
     */
    public function parentVersionNode()
    {
        return $this->belongsTo(VersionNode::class, 'parent_version_node_id');
    }

    /** The choices offered on this node - her ChoiceRoadmapNode.choices. */
    public function choices()
    {
        return $this->hasMany(VersionNode::class, 'parent_version_node_id');
    }

    /**
     * The roadmap this node opens instead of a page of its own.
     *
     * Her RoadmapChoice.separate_roadmap: React is too big to be one module,
     * so the choice hands off to a roadmap.
     */
    public function linksToRoadmap()
    {
        return $this->belongsTo(Roadmap::class, 'links_to_roadmap_id');
    }

    /** Modules in the rail, as opposed to choices hanging off one. */
    public function scopeInRail($query)
    {
        return $query->whereNull('parent_version_node_id');
    }

    /**
     * What this node does when a student clicks it, in her vocabulary.
     *
     * - roadmap: opens a roadmap of its own (separate_roadmap)
     * - choice:  offers buttons, each with its own page
     * - standard: has a page of its own
     */
    public function kind(): string
    {
        if ($this->links_to_roadmap_id !== null) {
            return 'roadmap';
        }

        return $this->choices()->exists() ? 'choice' : 'standard';
    }

    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class);
    }

    public function node(){
        
        return $this->belongsTo(Node::class);
    }

    public function tutorials()
    {
        return $this->hasMany(Tutorial::class);
    }

    public function projects()
    {
        return $this->hasMany(Project::class);
    }

    public function quizzes()
    {
        return $this->hasMany(Quiz::class);
    }

    public function externalResources()
    {
        return $this->hasMany(ExternalResources::class);
    }

    public function workshops()
    {
        return $this->hasMany(Workshop::class);
    }
}
