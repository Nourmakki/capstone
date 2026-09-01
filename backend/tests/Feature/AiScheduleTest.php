<?php

namespace Tests\Feature;

use App\Models\AiSchedule;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The daily trend scan's switch.
 *
 * The run spends API quota and takes minutes, so the point of this is being
 * able to stop it firing unattended - before a demo, most of all.
 */
class AiScheduleTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    /**
     * Nobody wants a scheduler that starts spending on the day it is
     * installed, so it waits to be turned on.
     */
    public function test_the_scan_is_off_until_someone_turns_it_on(): void
    {
        $this->assertFalse(AiSchedule::current()->enabled);

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/ai-schedule')
            ->assertOk();

        $this->assertFalse($response->json('enabled'));
        $this->assertSame('07:00', $response->json('run_at'));
    }

    public function test_a_manager_can_turn_it_on_and_off(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/ai-schedule', ['enabled' => true])
            ->assertOk();

        $this->assertTrue(AiSchedule::current()->enabled);

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/ai-schedule', ['enabled' => false])
            ->assertOk();

        $this->assertFalse(AiSchedule::current()->enabled);
    }

    /** Moving the run out of the way of a demo should not need a deploy. */
    public function test_the_time_can_be_changed(): void
    {
        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson('/api/ai-schedule', ['run_at' => '23:30'])
            ->assertOk();

        $this->assertSame('23:30', AiSchedule::current()->run_at);
    }

    public function test_a_time_that_is_not_a_time_is_refused(): void
    {
        foreach (['7am', '25:00', '07:60', 'noon'] as $bad) {
            $this->actingAs($this->makeManager(), 'sanctum')
                ->putJson('/api/ai-schedule', ['run_at' => $bad])
                ->assertStatus(422);
        }

        $this->assertSame('07:00', AiSchedule::current()->run_at);
    }

    public function test_only_staff_may_touch_the_switch(): void
    {
        foreach (['makeStudent', 'makeProfessor'] as $maker) {
            $this->actingAs($this->{$maker}(), 'sanctum')
                ->putJson('/api/ai-schedule', ['enabled' => true])
                ->assertForbidden();
        }

        $this->assertFalse(AiSchedule::current()->enabled);
    }

    /** Who last touched it, so an unexpected run can be traced to a person. */
    public function test_the_change_records_who_made_it(): void
    {
        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->putJson('/api/ai-schedule', ['enabled' => true])
            ->assertOk();

        $this->assertSame($manager->id, AiSchedule::current()->updated_by);
    }

    /**
     * The switch is read on every tick rather than when the schedule is built,
     * so turning it off takes effect without restarting anything.
     */
    public function test_the_scheduled_command_is_skipped_while_it_is_off(): void
    {
        $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
            ->filter(fn ($e) => str_contains($e->command ?? '', 'ai-workflow:run'));

        $this->assertCount(1, $events, 'The daily scan should be scheduled exactly once.');

        $event = $events->first();

        AiSchedule::current()->update(['enabled' => false]);
        $this->assertTrue($event->filtersPass(app()) === false || $event->isDue(app()) === false);

        AiSchedule::current()->update(['enabled' => true]);
        $this->assertTrue($event->filtersPass(app()));
    }
}
