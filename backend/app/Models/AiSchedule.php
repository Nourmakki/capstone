<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The daily trend scan's switch.
 *
 * One row, read by the scheduler on every tick and written by whoever is
 * running the platform. Kept in the database rather than in config so it can
 * be turned off from the app - which is the whole point of it, since the run
 * spends API quota and nobody wants it firing unattended during a demo.
 */
class AiSchedule extends Model
{
    protected $table = 'ai_schedule';

    protected $fillable = [
        'enabled',
        'run_at',
        'last_run_at',
        'last_run_summary',
        'updated_by',
    ];

    protected $casts = [
        'enabled'     => 'boolean',
        'last_run_at' => 'datetime',
    ];

    /**
     * The single row, created if the table is somehow empty.
     *
     * Every caller goes through this rather than first() - a null here would
     * mean the scheduler silently deciding it was switched off.
     */
    public static function current(): self
    {
        return static::firstOrCreate([], [
            'enabled' => false,
            'run_at'  => '07:00',
        ]);
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
