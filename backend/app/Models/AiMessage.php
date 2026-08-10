<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use App\Models\AiConversation;

class AiMessage extends Model
{
    use HasFactory;

    protected $fillable = [
        'conversations_id',
        'sender',
        'message'
    ];
    
    public function conversation()
    {
        return $this->belongsTo(AiConversation::class);
    }
}
