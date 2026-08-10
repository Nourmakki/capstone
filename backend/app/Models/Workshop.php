<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\RoadmapVersion;

class Workshop extends Model
{
    use HasFactory;

    protected $fillable = [
        'roadmap_versions_id',
        'title',
        'description',
        'scheduled_at',
        'duration_minutes',
        'location',
        'url'
    ];

    public function roadmapVersion()
    {
        return $this->belongsTo(RoadmapVersion::class);
    }
}
