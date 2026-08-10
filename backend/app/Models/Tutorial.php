<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\VersionNode;

class Tutorial extends Model
{
    use HasFactory;

    protected $fillable = [
    'version_node_id',
    'title',
    'url',
    'description',
    'source_name',
    'difficulty'
];

    public function versionNode()
    {
        return $this->belongsTo(VersionNode::class);
    }
}
