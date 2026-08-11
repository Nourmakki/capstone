<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Submission;
use App\Models\VersionNode;

class Project extends Model
{
    use HasFactory;

        protected $fillable = [
        'version_node_id',
        'title',
        'description',
        'difficulty',
        'github_url',
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
