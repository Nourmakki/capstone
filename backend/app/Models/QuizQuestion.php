<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Quiz;

class QuizQuestion extends Model
{
    use HasFactory;

    protected $fillable = [
        'quiz_id',
        'question',
        'type',
        'options',
        'answer',
        'ai_metadata',
    ];

    protected $casts = [
        'options' => 'array',
        'ai_metadata' => 'array',
    ];

    /**
     * Never serialise the correct answer. Grading happens server-side;
     * anything that needs the answer must read it explicitly.
     *
     * ai_metadata is hidden for the same reason: the quiz agent puts
     * correct_answer_explanation in there, which gives the answer away as
     * surely as the answer column does.
     */
    protected $hidden = ['answer', 'ai_metadata'];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}