<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\VersionNode;
use App\Models\Submission;
use App\Models\QuizQuestion;

class Quiz extends Model
{
    use HasFactory;

        protected $fillable = [
        'version_node_id',
        'title',
        'score'
    ];

    public function versionNode()
    {
        return $this->belongsTo(VersionNode::class, 'version_nodes_id');
    }

    public function submissions()
    {
        return $this->hasMany(Submission::class);
    }

    public function questions()
    {
        return $this->hasMany(QuizQuestion::class);
    }

}
