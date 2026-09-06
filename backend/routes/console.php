<?php

use App\Models\AiSchedule;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
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

/*
| A heartbeat, so the app can tell whether any of this is running at all.
|
| Laravel's schedule is a definition. Something outside has to run
| `schedule:run` every minute for a due task to fire - cron on Linux, Task
| Scheduler or `schedule:work` on Windows. With nothing doing that, a daily
| task simply never happens: no error, no log, no run, because no code
| executes.
|
| That cost a night once. The switch said "Daily scan on - 07:00" while
| nothing existed to honour it, and seven o'clock came and went in silence.
| Writing the time on every tick lets the page say whether the scan can
| actually fire, instead of reporting a setting as though it were a promise.
*/
Schedule::call(fn () => Cache::put('scheduler.last_tick', now()->toIso8601String(), now()->addDay()))
    ->everyMinute()
    ->name('scheduler-heartbeat')
    ->withoutOverlapping();

/*
| Read against the manager's own clock, not the app's.
|
| Timestamps are UTC and stay UTC. This is only about what the time on the
| page means: somebody in Beirut asking for 07:00 was scheduling 07:00 UTC,
| which is 10:00 to them, and nothing on the screen said which of the two it
| meant. The scan then ran three hours after they expected it and looked like
| it had ignored them.
|
| SCHEDULE_TIMEZONE sets it. Unset, it falls back to the app timezone, so an
| installation that never thought about this behaves exactly as before - and
| the page names the zone either way, so the answer is on screen rather than
| in a config file.
*/
Schedule::command('ai-workflow:run')
    ->dailyAt($runAt)
    ->timezone(config('app.schedule_timezone'))
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
