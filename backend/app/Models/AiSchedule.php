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
        'max_roadmaps',
        'only_version_ids',
        'last_run_at',
        'last_run_summary',
        'updated_by',
    ];

    protected $casts = [
        'enabled'          => 'boolean',
        'last_run_at'      => 'datetime',
        'only_version_ids' => 'array',
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

    /**
     * The published versions this scan should take on, in order.
     *
     * Both settings narrow it and neither is required: no chosen versions
     * means every published one, and no cap means all of those. A cap on top
     * of a chosen set is honoured, so "these three, at most one" is a
     * sentence the two can make together.
     *
     * @return \Illuminate\Support\Collection<int,RoadmapVersion>
     */
    public function versionsInScope()
    {
        $query = RoadmapVersion::where('status', 'Published');

        if (! empty($this->only_version_ids)) {
            $query->whereIn('id', $this->only_version_ids);
        }

        // Oldest first, so a capped run works through them in a stable order
        // rather than showing the same newest roadmap every time.
        $query->orderBy('id');

        if ($this->max_roadmaps) {
            $query->limit($this->max_roadmaps);
        }

        return $query->get();
    }

    public function updatedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }
}
