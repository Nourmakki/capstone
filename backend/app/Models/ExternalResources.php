<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\RoadmapVersion;

class ExternalResources extends Model
{
    use HasFactory;

    protected $fillable = [
        'roadmap_versions_id',
        'title',
        'url'
    ];

    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class);
    }
}
