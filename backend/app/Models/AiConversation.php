<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\User;
use App\Models\AiMessage;

class AiConversation extends Model
{
    use HasFactory;

    protected $fillable = [
        'users_id',
        'conversation_data'
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function messages()
    {
        return $this->hasMany(AiMessage::class, 'conversation_id');
    }
    
}
