<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;
use App\Models\TeamMember;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description'
    ];

    public function students()
    {
        return $this->belongsToMany(
            Student::class,
            'team_members'
        )->withTimestamps();
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class);
    }
}
