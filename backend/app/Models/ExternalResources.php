<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\VersionNode;

class ExternalResources extends Model
{
    use HasFactory;

        protected $fillable = [
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
