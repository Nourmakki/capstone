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
    ];

    protected $casts = [
        'options' => 'array',
    ];

    /**
     * Never serialise the correct answer. Grading happens server-side;
     * anything that needs the answer must read it explicitly.
     */
    protected $hidden = ['answer'];

    public function quiz()
    {
        return $this->belongsTo(Quiz::class);
    }
}