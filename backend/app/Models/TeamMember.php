<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Student;
use App\Models\Team;

class TeamMember extends Model
{
    use HasFactory;

    protected $fillable = [
        'teams_id',
        'students_id'
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }
}
