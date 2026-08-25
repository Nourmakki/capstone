<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;
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
     * The uploaded archive goes with the row. Without this every deleted
     * submission leaves its ZIP on the private disk forever, referenced by
     * nothing.
     *
     * Note this does not fire when the database cascades the row away, which
     * happens when a student is deleted - see User::booted().
     */
    protected static function booted(): void
    {
        static::deleting(function (self $submission) {
            if ($submission->file_path && Storage::exists($submission->file_path)) {
                Storage::delete($submission->file_path);
            }
        });
    }

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
