<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;

class ToDoTask extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'title',
        'description',
        'due_date',
        'is_completed',
        'priority',
        'reminder_time',
    ];

    protected $casts = [
        'is_completed' => 'boolean',
        'due_date'     => 'date',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }
}