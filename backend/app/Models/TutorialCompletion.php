<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * One student saying they have watched one tutorial.
 */
class TutorialCompletion extends Model
{
    protected $fillable = [
        'student_id',
        'tutorial_id',
        'completed_at',
    ];

    protected $casts = [
        'completed_at' => 'datetime',
    ];

    public function student()
    {
        return $this->belongsTo(Student::class);
    }

    public function tutorial()
    {
        return $this->belongsTo(Tutorial::class);
    }
}
