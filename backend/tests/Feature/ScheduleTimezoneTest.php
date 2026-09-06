<?php

namespace Tests\Feature;

use App\Models\AiSchedule;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The clock the daily scan's time is read against.
 *
 * A manager types 07:00 meaning seven in the morning where they are. The app
 * keeps its timestamps in UTC, correctly, and the scheduler used to read that
 * 07:00 as UTC too - so a manager in Beirut got a scan at ten, three hours
 * after they had asked for it, with nothing on the page saying which of the
 * two clocks it had meant.
 *
 * schedule_timezone is what closes that gap, and these hold it shut. The
 * failure it prevents is silent: the scan still runs, still reports success,
 * and is simply hours from where somebody put it.
 */
class ScheduleTimezoneTest extends TestCase
{
    use RefreshDatabase;

    /** The daily scan, as the scheduler has it registered. */
    private function scanEvent()
    {
        return collect(app(Schedule::class)->events())
            ->first(fn ($event) => str_contains($event->command ?? '', 'ai-workflow:run'));
    }

    public function test_the_scan_is_scheduled_against_the_configured_clock(): void
    {
        $event = $this->scanEvent();

        $this->assertNotNull($event, 'the daily scan should be registered');
        $this->assertSame(config('app.schedule_timezone'), $event->timezone);
    }

    /**
     * The invariant the fix is: whatever clock is configured, the scan fires
     * at the time the manager typed on that clock.
     *
     * Asserted against the event's own zone rather than a hard-coded one, so
     * this holds on a UTC install and on Nour's machine alike. Config set
     * after boot cannot move an already-registered schedule, which is what
     * makes the naive version of this test pass for the wrong reason.
     */
    public function test_it_fires_at_the_chosen_time_on_the_configured_clock(): void
    {
        $event = $this->scanEvent();
        $runAt = AiSchedule::current()->run_at;

        $next = $event->nextRunDate('2026-09-06 00:00:00');

        $this->assertSame(
            $runAt,
            $next->copy()->setTimezone($event->timezone)->format('H:i'),
            'the scan should fire at the chosen time on the manager clock'
        );
    }

    /**
     * And the regression itself: on an install whose clock is not UTC, the
     * scan must not fire at the chosen time UTC. That is exactly what it did
     * - 07:00 in Beirut became a scan at ten, and nothing said why.
     */
    public function test_it_does_not_fire_at_the_chosen_time_in_utc_when_the_clock_differs(): void
    {
        $event = $this->scanEvent();

        if ($event->timezone === 'UTC' || $event->timezone === null) {
            $this->markTestSkipped('This install schedules in UTC, so there is no offset to get wrong.');
        }

        $next = $event->nextRunDate('2026-09-06 00:00:00');

        $this->assertNotSame(
            AiSchedule::current()->run_at,
            $next->copy()->setTimezone('UTC')->format('H:i')
        );
    }

    /** The page has to say which clock, or the number on it means two things. */
    public function test_the_page_is_told_which_clock(): void
    {
        config(['app.schedule_timezone' => 'Asia/Beirut']);

        $manager = \App\Models\User::factory()->create(['role' => 'MaterialManager']);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/ai-schedule')
            ->assertOk()
            ->assertJsonPath('timezone', 'Asia/Beirut');
    }
}
