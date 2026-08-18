<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\UserRoadmapProgress;
use App\Models\Submission;
use App\Models\Team;
use App\Models\TeamMember;

class Student extends Model
{
    use HasFactory;
    protected $fillable = [
        'user_id',
        'level'
    ];

     public function user()
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function roadmapProgress()
    {
        return $this->hasMany(UserRoadmapProgress::class);
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function teams()
    {
        return $this->belongsToMany(
            Team::class,
            'team_members'
        )->withTimestamps();
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class);
    }
}
