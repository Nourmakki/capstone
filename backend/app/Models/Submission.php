<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Project;
use App\Models\Student;
use App\Models\Quiz;
use App\Models\HumanReviewRequest;

class Submission extends Model
{
    use HasFactory;
    protected $fillable = [
        'project_id',
        'student_id',
        'quiz_id',
        'content',
        'status',
        'score',
        'comments',
    ];

    protected $casts = [
        'score' => 'integer',
    ];
    public function project()
    {
        return $this->belongsTo(Project::class);
    }

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }

    public function humanReviewRequests()
    {
        return $this->hasMany(HumanReviewRequest::class);
    }
}
