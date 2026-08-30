<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Submission;
use App\Models\VersionNode;

class Project extends Model
{
    use HasFactory, Reviewable;

        protected $fillable = [
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'change_note',
        'version_node_id',
        'title',
        'description',
        'difficulty',
        'github_url',
        'ai_metadata',
    ];

    protected $casts = [
        'ai_metadata' => 'array',
    ];

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function versionNode()
    {
        return $this->belongsTo(VersionNode::class);
    }
}
