<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSchedule;
use App\Models\RoadmapVersion;
use Carbon\Carbon;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use App\Console\Commands\RunAiRoadmapWorkflows;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;

/**
 * The switch for the daily trend scan.
 *
 * Every published roadmap goes to the AI once a day to be asked whether
 * anything new has appeared in its subject, and what comes back queues for
 * review. That run spends API quota and takes minutes, so it is off until
 * somebody turns it on and can be turned off again from the app - a demo
 * should not be interrupted by the platform talking to an AI on its own.
 */
class AiScheduleController extends Controller
{
    public function show(): JsonResponse
    {
        return response()->json($this->payload(AiSchedule::current()));
    }

    public function update(Request $request): JsonResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            // 24-hour local time. Kept in the row so the run can be moved out
            // of the way of a demo without a deploy.
            'run_at'  => ['sometimes', 'string', 'regex:/^([01]\d|2[0-3]):[0-5]\d$/'],

            // How much a run takes on. Null on either means no limit.
            'max_roadmaps'       => ['sometimes', 'nullable', 'integer', 'min:1', 'max:100'],
            'only_version_ids'   => ['sometimes', 'nullable', 'array'],
            'only_version_ids.*' => ['integer', 'exists:roadmap_versions,id'],
        ], [
            'run_at.regex' => 'Give the time as HH:MM on a 24-hour clock, for example 07:00.',
        ]);

        $schedule = AiSchedule::current();

        $schedule->update($data + ['updated_by' => $request->user()->id]);

        return response()->json([
            'message'  => ($schedule->enabled ? 'Daily scan is on.' : 'Daily scan is off.')
                . ' Changes take effect immediately - the switch is read on every tick.',
            'schedule' => $this->payload($schedule->fresh()),
        ]);
    }

    /**
     * Run the scan now, without waiting for the hour.
     *
     * Inline, not queued. The queue driver here is the database, so a queued
     * job waits for a worker - which is another terminal command, and being
     * free of one of those is the whole point of this button. The scan
     * respects the same scope as a scheduled run, so "at most one roadmap"
     * is what makes this bearable to press: unlimited, it is fifteen calls
     * to the AI held open on one request.
     *
     * Long, either way. The same shape as the Generate button on the
     * learning page, which has run inline for the same reason since it was
     * written.
     */
    public function runNow(Request $request): JsonResponse
    {
        $schedule = AiSchedule::current();

        $command = app(RunAiRoadmapWorkflows::class);

        try {
            Artisan::call($command->getName());
        } catch (\Throwable $e) {
            report($e);

            return response()->json([
                'message' => 'The scan could not be started: ' . $e->getMessage(),
            ], 500);
        }

        $summary = $this->lastRunSummary();

        $schedule->update([
            'last_run_at'      => now(),
            'last_run_summary' => $summary,
            'updated_by'       => $request->user()->id,
        ]);

        return response()->json([
            'message'  => $summary,
            'output'   => trim(Artisan::output()),
            'schedule' => $this->payload($schedule->fresh()),
        ]);
    }

    /**
     * What the run just did, in one line.
     *
     * Read off the command's own output rather than counting rows again: the
     * command knows which versions it skipped and why, and a second count
     * here could disagree with it.
     */
    private function lastRunSummary(): string
    {
        $lines = array_values(array_filter(
            array_map('trim', explode("\n", Artisan::output()))
        ));

        if ($lines === []) {
            return 'Ran, but reported nothing.';
        }

        $started = count(array_filter($lines, fn ($l) => str_ends_with($l, ': started.')));
        $skipped = count(array_filter($lines, fn ($l) => str_contains($l, ': skipped,')));
        $failed  = count(array_filter($lines, fn ($l) => str_contains($l, ': failed,')));

        if ($started === 0 && $skipped === 0 && $failed === 0) {
            return $lines[0];
        }

        $parts = [];

        if ($started) $parts[] = $started . ' started';
        if ($skipped) $parts[] = $skipped . ' already waiting for review';
        if ($failed)  $parts[] = $failed . ' failed';

        return ucfirst(implode(', ', $parts)) . '.';
    }

    /**
     * Is anything actually running the scheduler?
     *
     * Turning the switch on only records an intention. A due task fires when
     * something outside runs `schedule:run` every minute - cron, Task
     * Scheduler, or `schedule:work` in a terminal. With nothing doing that,
     * the switch reads "on" and the scan never happens: no error, no log, no
     * run, because no code executes.
     *
     * The heartbeat in routes/console.php writes a timestamp every tick, so
     * a recent one is proof something is running. Two minutes of slack, since
     * a tick is a minute apart and a slow one should not read as an outage.
     */
    private function schedulerRunning(): bool
    {
        $tick = Cache::get('scheduler.last_tick');

        if (! $tick) {
            return false;
        }

        try {
            return Carbon::parse($tick)->gt(now()->subMinutes(2));
        } catch (\Throwable) {
            return false;
        }
    }

    private function payload(AiSchedule $schedule): array
    {
        return [
            'enabled'          => $schedule->enabled,
            'run_at'           => $schedule->run_at,

            // What a run takes on. Null on either means no limit.
            'max_roadmaps'     => $schedule->max_roadmaps,
            'only_version_ids' => $schedule->only_version_ids ?? [],

            // What it would actually reach right now, so the page can say
            // "1 of 15" rather than leaving the settings to be read together.
            'in_scope'         => $schedule->versionsInScope()->count(),
            'last_run_at'      => $schedule->last_run_at,
            'last_run_summary' => $schedule->last_run_summary,
            'updated_by'       => $schedule->updatedBy->name ?? null,

            // Which clock run_at is read against. On screen because it is
            // the difference between a scan at seven and one at ten, and
            // there is nothing in "07:00" that says which was meant.
            'timezone'          => config('app.schedule_timezone'),

            // Whether the switch can be honoured at all. On is a setting;
            // this is whether anything exists to act on it.
            'scheduler_running' => $this->schedulerRunning(),

            // What a run would actually do, so the switch is not flipped
            // blind: this many roadmaps would each be sent to the AI.
            'roadmaps_in_scope' => RoadmapVersion::where('status', 'Published')->count(),
        ];
    }
}
