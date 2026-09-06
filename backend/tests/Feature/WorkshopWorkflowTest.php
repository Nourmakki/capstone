<?php

namespace Tests\Feature;

use App\Models\VersionNode;
use App\Models\Workshop;
use App\Models\WorkshopAiWorkflow;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The workshop agent, wired to the platform.
 *
 * Her workflow is asked for a topic and answers with listings off the web.
 * The manager approves all of them or some of them; the approved ones are
 * written onto a roadmap version, where the student page can match them.
 *
 * Everything here fakes her service. The point under test is the wiring - the
 * shape we send her, and what we do with the shape she sends back.
 */
class WorkshopWorkflowTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
    }

    /** One workshop in her shape. */
    private function workshop(array $overrides = []): array
    {
        return array_merge([
            'title'     => 'React Summit',
            'organizer' => 'React Summit Ltd',
            'speaker'   => 'Someone Known',
            'date'      => '2026-11-14',
            'time'      => '09:30',
            'location'  => 'Amsterdam, Netherlands',
            'cost_type' => 'paid',
            'price'     => 'EUR 400',
            'link'      => 'https://reactsummit.example/2026',
        ], $overrides);
    }

    private function aiReplies(array $body): void
    {
        Http::fake([
            '*/workshop-workflows*' => Http::response($body, 200),
        ]);
    }

    private function runFor($version, array $attributes = []): WorkshopAiWorkflow
    {
        return WorkshopAiWorkflow::create(array_merge([
            'roadmap_version_id' => $version->id,
            'thread_id'          => 'workshop-thread-1',
            'topic'              => 'Frontend',
            'status'             => 'awaiting_review',
            'review_stage'       => 'workshops',
        ], $attributes));
    }

    // -----------------------------------------------------------------
    // Starting one
    // -----------------------------------------------------------------

    public function test_starting_a_run_asks_only_for_the_roadmap_topic(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $this->aiReplies([
            'thread_id'           => 'workshop-thread-1',
            'status'              => 'awaiting_review',
            'review_stage'        => 'workshops',
            'data'                => ['workshops' => [$this->workshop()]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/workshop-runs")
            ->assertCreated();

        // The topic and nothing else. Her model forbids extra keys, and a
        // location would narrow the search for every student at once - which
        // is the student page's decision, made per student, not this one.
        Http::assertSent(function ($request) {
            return $request->url() === config('services.ai_system.url') . '/workshop-workflows'
                && $request->data() === ['roadmap_topic' => 'Test Roadmap'];
        });

        $this->assertDatabaseHas('workshop_ai_workflows', [
            'roadmap_version_id' => $version->id,
            'thread_id'          => 'workshop-thread-1',
            'topic'              => 'Test Roadmap',
            'status'             => 'awaiting_review',
        ]);
    }

    /**
     * And a client that sends one anyway does not get it honoured. The field
     * is gone from the request rules, so it never reaches her.
     */
    public function test_a_location_sent_by_a_client_is_ignored(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $this->aiReplies([
            'thread_id'           => 'workshop-thread-2',
            'status'              => 'awaiting_review',
            'data'                => ['workshops' => []],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/workshop-runs", [
                'student_location' => 'Beirut, Lebanon',
            ])
            ->assertCreated();

        Http::assertSent(fn ($request) => $request->data() === ['roadmap_topic' => 'Test Roadmap']);
    }

    public function test_a_module_from_another_version_is_refused(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        ['nodes' => $otherNodes, 'version' => $other] = $this->makeRoadmap(['Elsewhere']);

        $strayNode = VersionNode::where('roadmap_version_id', $other->id)->first();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/workshop-runs", [
                'version_node_id' => $strayNode->id,
            ])
            ->assertStatus(422);

        $this->assertDatabaseCount('workshop_ai_workflows', 0);
    }

    // -----------------------------------------------------------------
    // Reviewing
    // -----------------------------------------------------------------

    public function test_approving_everything_sends_no_data_at_all(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'ready_for_commit',
            'data'                => ['workshops' => [$this->workshop()]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/resume", ['action' => 'approve'])
            ->assertOk();

        // data=None is her way of saying "all of them". Sending an empty
        // object instead would fail her min_length on the link list.
        Http::assertSent(fn ($request) => $request->data() === ['action' => 'approve']);

        $this->assertSame('ready_for_commit', $run->fresh()->status);
    }

    public function test_approving_some_sends_only_their_links(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'ready_for_commit',
            'data'                => ['workshops' => [$this->workshop()]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/resume", [
                'action'                  => 'approve',
                'approved_workshop_links' => ['https://one.example', 'https://two.example'],
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request->data() === [
            'action' => 'approve',
            'data'   => ['approved_workshop_links' => ['https://one.example', 'https://two.example']],
        ]);
    }

    public function test_the_same_link_twice_is_refused_before_she_sees_it(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/resume", [
                'action'                  => 'approve',
                'approved_workshop_links' => ['https://one.example', 'https://one.example'],
            ])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_rejecting_carries_feedback_and_no_data(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'rejected',
            'data'                => ['workshops' => []],
            'rejection_feedback'  => 'All of them are in the wrong country.',
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/resume", [
                'action'   => 'reject',
                'feedback' => 'All of them are in the wrong country.',
            ])
            ->assertOk();

        Http::assertSent(fn ($request) => $request->data() === [
            'action'   => 'reject',
            'feedback' => 'All of them are in the wrong country.',
        ]);
    }

    // -----------------------------------------------------------------
    // Committing
    // -----------------------------------------------------------------

    public function test_committing_writes_the_approved_workshops_onto_the_version(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);
        $node = VersionNode::where('roadmap_version_id', $version->id)->first();

        $run = $this->runFor($version, [
            'status'          => 'ready_for_commit',
            'version_node_id' => $node->id,
        ]);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'ready_for_commit',
            'data'                => ['workshops' => [$this->workshop()]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/commit")
            ->assertCreated()
            ->assertJsonPath('created', 1);

        $workshop = Workshop::first();

        $this->assertSame('React Summit', $workshop->title);
        $this->assertSame('React Summit Ltd', $workshop->organizer);
        $this->assertSame('Someone Known', $workshop->speaker);
        $this->assertSame('paid', $workshop->cost_type);
        $this->assertSame('EUR 400', $workshop->price);
        $this->assertSame('https://reactsummit.example/2026', $workshop->url);
        $this->assertSame($node->id, $workshop->version_node_id);

        // Her date plus her time.
        $this->assertSame('2026-11-14 09:30', $workshop->scheduled_at->format('Y-m-d H:i'));

        // The tail of the location, which is what the student page matches on.
        $this->assertSame('Netherlands', $workshop->country);

        // No duration: a listing says when a thing starts, not how long it runs.
        $this->assertNull($workshop->duration_minutes);

        // What she sent, kept whole.
        $this->assertSame('React Summit', $workshop->ai_metadata['title']);

        $this->assertNotNull($run->fresh()->committed_at);
    }

    public function test_an_online_workshop_has_no_country_so_everyone_sees_it(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version, ['status' => 'ready_for_commit']);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'ready_for_commit',
            'data'                => ['workshops' => [
                $this->workshop(['location' => 'Online', 'cost_type' => 'free', 'price' => null]),
            ]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/commit")
            ->assertCreated();

        $this->assertNull(Workshop::first()->country);
    }

    public function test_a_missing_time_falls_back_to_the_start_of_the_day(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version, ['status' => 'ready_for_commit']);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'ready_for_commit',
            'data'                => ['workshops' => [$this->workshop(['time' => null])]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/commit")
            ->assertCreated();

        $this->assertSame('2026-11-14 00:00', Workshop::first()->scheduled_at->format('Y-m-d H:i'));
    }

    /**
     * The same conference offered by two runs is one workshop, not two. Her
     * link is the identifier, since a manager searching the same topic twice
     * will be shown the same listings.
     */
    public function test_committing_the_same_link_twice_updates_rather_than_duplicates(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        // Faked per thread rather than restubbed between the two commits:
        // Http::fake() merges stubs rather than replacing them, so a second
        // call is never reached and the first answer is given twice.
        Http::fake(function ($request) {
            $second = str_contains($request->url(), 'workshop-thread-b');

            return Http::response([
                'thread_id'           => $second ? 'workshop-thread-b' : 'workshop-thread-a',
                'status'              => 'ready_for_commit',
                'data'                => ['workshops' => [
                    $this->workshop(['title' => $second ? 'React Summit 2026' : 'React Summit']),
                ]],
                'generation_attempts' => 1,
            ], 200);
        });

        foreach (['a', 'b'] as $suffix) {
            $run = $this->runFor($version, [
                'thread_id' => "workshop-thread-{$suffix}",
                'status'    => 'ready_for_commit',
            ]);

            $this->actingAs($this->makeManager(), 'sanctum')
                ->postJson("/api/workshop-runs/{$run->id}/commit")
                ->assertCreated();
        }

        $this->assertSame(1, Workshop::count());
        $this->assertSame('React Summit 2026', Workshop::first()->title);
    }

    public function test_a_run_that_is_not_approved_yet_cannot_be_committed(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'awaiting_review',
            'data'                => ['workshops' => [$this->workshop()]],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/commit")
            ->assertStatus(409);

        $this->assertSame(0, Workshop::count());
    }

    public function test_committing_nothing_says_so_and_leaves_the_run_open(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version, ['status' => 'ready_for_commit']);

        $this->aiReplies([
            'thread_id'           => $run->thread_id,
            'status'              => 'ready_for_commit',
            'data'                => ['workshops' => []],
            'generation_attempts' => 1,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/workshop-runs/{$run->id}/commit")
            ->assertStatus(422);

        $this->assertNull($run->fresh()->committed_at);
    }

    // -----------------------------------------------------------------
    // The list, and clearing it
    // -----------------------------------------------------------------

    public function test_clearing_a_run_leaves_the_workshops_it_added(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $run = $this->runFor($version, ['status' => 'committed', 'committed_at' => now()]);

        $workshop = Workshop::create([
            'roadmap_version_id' => $version->id,
            'title'              => 'React Summit',
            'scheduled_at'       => now()->addMonth(),
            'location'           => 'Amsterdam, Netherlands',
            'url'                => 'https://reactsummit.example/2026',
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->deleteJson("/api/workshop-runs/{$run->id}")
            ->assertOk();

        $this->assertDatabaseMissing('workshop_ai_workflows', ['id' => $run->id]);
        $this->assertDatabaseHas('workshops', ['id' => $workshop->id]);
    }

    public function test_a_student_may_not_drive_the_workshop_agent(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/workshop-runs")
            ->assertForbidden();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson('/api/workshop-runs')
            ->assertForbidden();

        Http::assertNothingSent();
    }

    /**
     * The half of the arrangement the manager is freed from.
     *
     * They approve everything worth approving, from anywhere; For You is what
     * narrows it, per student, against their own country - and shows an online
     * one to all of them because it is nobody's country and everybody's.
     */
    public function test_for_you_narrows_by_the_students_own_country(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $near = Workshop::create([
            'roadmap_version_id' => $version->id,
            'title'              => 'Beirut JS',
            'scheduled_at'       => now()->addMonth(),
            'location'           => 'Beirut, Lebanon',
            'country'            => 'Lebanon',
            'url'                => 'https://beirutjs.example',
        ]);

        $far = Workshop::create([
            'roadmap_version_id' => $version->id,
            'title'              => 'Amsterdam React',
            'scheduled_at'       => now()->addMonth(),
            'location'           => 'Amsterdam, Netherlands',
            'country'            => 'Netherlands',
            'url'                => 'https://reactsummit.example',
        ]);

        $online = Workshop::create([
            'roadmap_version_id' => $version->id,
            'title'              => 'Anywhere Online',
            'scheduled_at'       => now()->addMonth(),
            'location'           => 'Online',
            'country'            => null,
            'url'                => 'https://online.example',
        ]);

        $student = $this->makeStudent(['location' => 'Beirut, Lebanon']);

        $forYou = collect($this->actingAs($student, 'sanctum')
            ->getJson('/api/workshops/recommended')
            ->assertOk()
            ->json('workshops'))
            ->pluck('title');

        $this->assertContains('Beirut JS', $forYou);
        $this->assertContains('Anywhere Online', $forYou);
        $this->assertNotContains('Amsterdam React', $forYou);

        // All Upcoming still carries everything the manager approved - the
        // filtering is a view, not a deletion.
        $all = collect($this->actingAs($student, 'sanctum')
            ->getJson('/api/workshops')
            ->assertOk()
            ->json('workshops'))
            ->pluck('title');

        $this->assertCount(3, $all);
        $this->assertContains('Amsterdam React', $all);
    }

    /** A committed workshop reaches the student page with its new fields. */
    public function test_a_committed_workshop_shows_on_the_student_page(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        Workshop::create([
            'roadmap_version_id' => $version->id,
            'title'              => 'React Summit',
            'organizer'          => 'React Summit Ltd',
            'speaker'            => 'Someone Known',
            'scheduled_at'       => now()->addMonth(),
            'location'           => 'Amsterdam, Netherlands',
            'country'            => 'Netherlands',
            'cost_type'          => 'paid',
            'price'              => 'EUR 400',
            'url'                => 'https://reactsummit.example/2026',
        ]);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson('/api/workshops')
            ->assertOk()
            ->assertJsonPath('workshops.0.title', 'React Summit')
            ->assertJsonPath('workshops.0.organizer', 'React Summit Ltd')
            ->assertJsonPath('workshops.0.speaker', 'Someone Known')
            ->assertJsonPath('workshops.0.cost_type', 'paid')
            ->assertJsonPath('workshops.0.price', 'EUR 400');
    }
}
