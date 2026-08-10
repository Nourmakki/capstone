<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\RoadmapVersion;

class Roadmap extends Model
{
    use HasFactory;
    protected $fillable = [
        'title',
        'description'
    ];

    public function versions()
    {
        return $this->hasMany(RoadmapVersion::class);
    }
}
