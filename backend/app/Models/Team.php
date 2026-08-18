<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;
use App\Models\TeamMember;
use App\Models\TeamMessage;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'created_by_student_id',
        'project_focus',
    ];

    public function students()
    {
        return $this->belongsToMany(Student::class, 'team_members')->withTimestamps();
    }

    public function teamMembers()
    {
        return $this->hasMany(TeamMember::class);
    }

    public function creator()
    {
        return $this->belongsTo(Student::class, 'created_by_student_id');
    }

    public function messages()
    {
        return $this->hasMany(TeamMessage::class);
    }
}