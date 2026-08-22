<?php

namespace App\Models;

use App\Models\Concerns\Reviewable;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\VersionNode;

class ExternalResources extends Model
{
    use HasFactory, Reviewable;

        protected $fillable = [
        'review_status',
        'reviewed_by',
        'reviewed_at',
        'review_notes',
        'version_node_id',
        'title',
        'url',
        'type',
    ];

    public function versionNode()
    {
        return $this->belongsTo(VersionNode::class);
    }
}
