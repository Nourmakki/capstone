<?php

namespace Tests\Feature;

use App\Models\AiSchedule;
use App\Models\Roadmap;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * How much the trend scan takes on.
 *
 * Sending every published roadmap at once is what a nightly job is for, and
 * unusable in a demonstration: fifteen roadmaps is fifteen calls to the AI,
 * minutes of waiting, and a review queue nobody can read through while people
 * watch. Both limits are optional and mean "everything" when unset.
 */
class ScanScopeTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();

        // A closure, so each call gets its own thread id. An array literal
        // evaluates uniqid() once and hands every roadmap the same one, which
        // the unique index on thread_id then refuses.
        Http::fake(fn () => Http::response([
            'thread_id'    => 'thread-' . uniqid('', true),
            'status'       => 'awaiting_review',
            'review_stage' => 'trends',
        ], 200));
    }

    /** Several published roadmaps, oldest first. */
    private function publishRoadmaps(int $count): array
    {
        $subject = Roadmap::create([
            'title'         => 'Web Development',
            'description'   => 'A heading.',
            'review_status' => 'approved',
        ]);

        $versions = [];

        for ($i = 1; $i <= $count; $i++) {
            $roadmap = Roadmap::create([
                'title'             => "Path {$i}",
                'description'       => "About path {$i}.",
                'review_status'     => 'approved',
                'parent_roadmap_id' => $subject->id,
            ]);

            $versions[] = RoadmapVersion::create([
                'roadmap_id'     => $roadmap->id,
                'version_number' => '1',
                'status'         => 'Published',
                'review_status'  => 'approved',
            ]);
        }

        return $versions;
    }

    public function test_by_default_every_published_roadmap_is_in_scope(): void
    {
        $this->publishRoadmaps(4);

        $this->assertCount(4, AiSchedule::current()->versionsInScope());
    }

    public function test_a_cap_takes_only_that_many(): void
    {
        $versions = $this->publishRoadmaps(4);

        AiSchedule::current()->update(['max_roadmaps' => 2]);

        $inScope = AiSchedule::current()->versionsInScope();

        $this->assertCount(2, $inScope);

        // Oldest first, so a capped run is stable rather than showing
        // whichever roadmap happens to be newest.
        $this->assertSame(
            [$versions[0]->id, $versions[1]->id],
            $inScope->pluck('id')->all()
        );
    }

    public function test_picking_roadmaps_takes_only_those(): void
    {
        $versions = $this->publishRoadmaps(4);

        AiSchedule::current()->update([
            'only_version_ids' => [$versions[2]->id],
        ]);

        $inScope = AiSchedule::current()->versionsInScope();

        $this->assertCount(1, $inScope);
        $this->assertSame($versions[2]->id, $inScope->first()->id);
    }

    /** "These three, at most one" is a sentence the two settings can make. */
    public function test_a_cap_applies_on_top_of_the_picks(): void
    {
        $versions = $this->publishRoadmaps(4);

        AiSchedule::current()->update([
            'only_version_ids' => [$versions[1]->id, $versions[2]->id, $versions[3]->id],
            'max_roadmaps'     => 1,
        ]);

        $inScope = AiSchedule::current()->versionsInScope();

        $this->assertCount(1, $inScope);
        $this->assertSame($versions[1]->id, $inScope->first()->id);
    }

    public function test_a_draft_roadmap_is_never_in_scope(): void
    {
        $versions = $this->publishRoadmaps(2);

        $versions[0]->update(['status' => 'Draft']);

        $this->assertCount(1, AiSchedule::current()->versionsInScope());
    }

    // -----------------------------------------------------------------
    // The command, and the button
    // -----------------------------------------------------------------

    public function test_the_command_honours_the_saved_scope(): void
    {
        $this->publishRoadmaps(4);

        AiSchedule::current()->update(['max_roadmaps' => 1]);

        $this->artisan('ai-workflow:run')->assertSuccessful();

        // One roadmap sent, not four.
        $this->assertSame(1, RoadmapAiWorkflow::count());
        Http::assertSentCount(1);
    }

    public function test_a_named_version_overrides_the_saved_scope(): void
    {
        $versions = $this->publishRoadmaps(4);

        AiSchedule::current()->update(['only_version_ids' => [$versions[0]->id]]);

        $this->artisan('ai-workflow:run', ['--roadmap-version' => $versions[3]->id])
            ->assertSuccessful();

        $this->assertSame(
            $versions[3]->id,
            RoadmapAiWorkflow::first()->roadmap_version_id
        );
    }

    public function test_a_roadmap_already_waiting_for_review_is_skipped(): void
    {
        $versions = $this->publishRoadmaps(1);

        RoadmapAiWorkflow::create([
            'roadmap_version_id' => $versions[0]->id,
            'thread_id'          => 'already-here',
            'status'             => 'awaiting_review',
        ]);

        $this->artisan('ai-workflow:run')->assertSuccessful();

        // Still just the one that was there.
        $this->assertSame(1, RoadmapAiWorkflow::count());
        Http::assertNothingSent();
    }

    public function test_a_manager_can_run_the_scan_without_waiting_for_the_hour(): void
    {
        $this->publishRoadmaps(3);

        AiSchedule::current()->update(['max_roadmaps' => 2]);

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/ai-schedule/run')
            ->assertOk();

        $this->assertSame(2, RoadmapAiWorkflow::count());
        $this->assertStringContainsString('2 started', $response->json('message'));

        // The row remembers it, the same way a scheduled run does.
        $this->assertNotNull(AiSchedule::current()->last_run_at);
    }

    public function test_a_student_cannot_run_the_scan(): void
    {
        $this->publishRoadmaps(1);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/ai-schedule/run')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    public function test_the_page_is_told_what_a_run_would_reach(): void
    {
        $this->publishRoadmaps(5);

        AiSchedule::current()->update(['max_roadmaps' => 2]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/ai-schedule')
            ->assertOk()
            ->assertJsonPath('in_scope', 2)
            ->assertJsonPath('roadmaps_in_scope', 5)
            ->assertJsonPath('max_roadmaps', 2);
    }
}
