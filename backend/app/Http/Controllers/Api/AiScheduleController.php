<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiSchedule;
use App\Models\RoadmapVersion;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

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

    private function payload(AiSchedule $schedule): array
    {
        return [
            'enabled'          => $schedule->enabled,
            'run_at'           => $schedule->run_at,
            'last_run_at'      => $schedule->last_run_at,
            'last_run_summary' => $schedule->last_run_summary,
            'updated_by'       => $schedule->updatedBy->name ?? null,

            // What a run would actually do, so the switch is not flipped
            // blind: this many roadmaps would each be sent to the AI.
            'roadmaps_in_scope' => RoadmapVersion::where('status', 'Published')->count(),
        ];
    }
}
