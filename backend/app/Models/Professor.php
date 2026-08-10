<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Professor extends Model
{
    use HasFactory;

    protected $fillable = [
        'users_id',
        'qualification',
        'is_verified'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewRequests()
    {
        return $this->hasMany(HumanReviewRequest::class);
    }
}
