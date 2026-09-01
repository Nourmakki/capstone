<?php

use App\Models\AiSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| The daily trend scan
|--------------------------------------------------------------------------
|
| Workflow one: for every published roadmap, ask the AI system whether
| anything new has appeared in that subject, and queue what it proposes for a
| manager to review.
|
| Two things guard it, and both matter.
|
| `skip` reads the switch on every tick rather than at boot, so turning the
| scheduler off in the app takes effect immediately - there is no process to
| restart. It is off until somebody turns it on, because a run spends API
| quota and starting unattended the day it is deployed is nobody's intent.
|
| `withoutOverlapping` because a scan of every roadmap can outlast the gap to
| the next tick, and two of them at once would double the cost and race each
| other to record the same runs.
|
| The time comes from the same row, so it can be moved out of the way of a
| demo without a deploy. Laravel wants it at schedule-build time, hence the
| read here; changing it takes effect on the next `schedule:run`.
*/
/*
| Read defensively. This file runs on every artisan command, including the
| `migrate` that creates the table - so reading it unguarded made a fresh
| install fail before it could build the schema it was asking for.
*/
$runAt = '07:00';

try {
    $runAt = AiSchedule::query()->value('run_at') ?: $runAt;
} catch (\Throwable) {
    // No table yet: the default stands until there is something to read.
}

Schedule::command('ai-workflow:run')
    ->dailyAt($runAt)
    ->withoutOverlapping()
    ->skip(fn () => ! AiSchedule::current()->enabled)
    ->onSuccess(fn () => AiSchedule::current()->update([
        'last_run_at'      => now(),
        'last_run_summary' => 'Completed.',
    ]))
    ->onFailure(fn () => AiSchedule::current()->update([
        'last_run_at'      => now(),
        'last_run_summary' => 'Failed - check the AI service is running.',
    ]));
