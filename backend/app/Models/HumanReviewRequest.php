<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\Submission;
use App\Models\Professor;

class HumanReviewRequest extends Model
{
    use HasFactory;

    protected $fillable = ['submissions_id', 'professors_id','feedback'];

    public function submission()
    {
        return $this->belongsTo(Submission::class);
    }

    public function professor()
    {
        return $this->belongsTo(Professor::class);
    }
}
