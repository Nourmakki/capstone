<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Storage;

class Professor extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'qualification',
        'cv_path',
        'is_verified'
    ];

    protected $casts = [
        'is_verified' => 'boolean',
    ];

    /**
     * The uploaded CV goes with the row.
     *
     * Note this does not fire when the database cascades the row away, which
     * happens whenever the owning user is deleted - see User::booted().
     */
    protected static function booted(): void
    {
        static::deleting(function (self $professor) {
            if ($professor->cv_path && Storage::exists($professor->cv_path)) {
                Storage::delete($professor->cv_path);
            }
        });
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function reviewRequests()
    {
        return $this->hasMany(HumanReviewRequest::class);
    }
}
