<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\Storage;
use App\Models\Submission;
use App\Models\Student;
use App\Models\Professor;
use App\Models\AiConversation;
use App\Models\ToDoTask;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var array<int, string>
     */
     protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'location'
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var array<int, string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    /**
     * The attributes that should be cast.
     *
     * @var array<string, string>
     */
    protected $casts = [
        'email_verified_at' => 'datetime',
    ];

    /**
     * professors.user_id and students.user_id both cascade at the database
     * level, and a database cascade fires no Eloquent events - so neither
     * Professor::booted() nor Submission::booted() ever runs when a user is
     * deleted. Their files are cleaned up here instead, before the row goes.
     */
    protected static function booted(): void
    {
        static::deleting(function (self $user) {
            $cv = $user->professor?->cv_path;

            if ($cv && Storage::exists($cv)) {
                Storage::delete($cv);
            }

            $studentId = $user->student?->id;

            if (! $studentId) {
                return;
            }

            Submission::where('student_id', $studentId)
                ->whereNotNull('file_path')
                ->pluck('file_path')
                ->each(function ($path) {
                    if (Storage::exists($path)) {
                        Storage::delete($path);
                    }
                });
        });
    }

      public function student()
    {
        return $this->hasOne(Student::class);
    }

    public function professor()
    {
        return $this->hasOne(Professor::class);
    }

    public function aiConversations()
    {
        return $this->hasMany(AiConversation::class);
    }

    public function todoTasks()
    {
        return $this->hasMany(ToDoTask::class);
    }
}
