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
        'github_url',
        'file_path',
        'status',
        'score',
        'comments',
        'ai_score',
        'ai_feedback',
        'ai_reviewed_at',
    ];

    protected $casts = [
        'score'          => 'integer',
        'ai_score'       => 'integer',
        'ai_reviewed_at' => 'datetime',
    ];

    /**
     * The grade that counts: a human review always wins over the AI.
     */
    public function finalScore(): ?int
    {
        return $this->score ?? $this->ai_score;
    }
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
