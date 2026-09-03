<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapBuilderRun;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Jobs\StartLearningRun;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The roadmap builder: a whole subject from a topic name.
 *
 * Her service is faked throughout - these assert what we send her, and what we
 * make of what comes back, not that her agents work.
 */
class RoadmapBuilderTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private const START = 'http://127.0.0.1:8001/roadmap-builder-workflows';
    private const THREAD = 'http://127.0.0.1:8001/roadmap-builder-workflows/*';

    /** A package shaped like her GeneratedPathRoadmapModel. */
    private function readyBody(): array
    {
        return [
            'thread_id'                    => 'build-1',
            'status'                       => 'ready_for_commit',
            'review_stage'                 => null,
            'scope_generation_attempts'    => 1,
            'roadmap_generation_attempts'  => 1,
            'data' => [
                'approved_roadmap_packages' => [[
                    'path_id'    => 1,
                    'path_title' => 'Frontend',
                    'roadmap_result' => [
                        'roadmap_topic'     => 'frontend',
                        'title'             => 'Frontend',
                        'description'       => 'Interfaces people actually use.',
                        'freshness_summary' => 'Current as of today.',
                        'nodes' => [
                            [
                                'type' => 'standard', 'node_id' => 1, 'order' => 1,
                                'title' => 'Web Fundamentals', 'description' => 'HTML, CSS and JavaScript.',
                                'prerequisite_node_ids' => [], 'learning_outcomes' => ['Build a page'],
                            ],
                            [
                                'type' => 'choice', 'node_id' => 2, 'order' => 2,
                                'title' => 'Pick a Framework', 'description' => 'One is enough.',
                                'prerequisite_node_ids' => [1], 'learning_outcomes' => ['Ship an app'],
                                'choices' => [
                                    [
                                        'choice_id' => 1, 'title' => 'React', 'description' => 'The React roadmap.',
                                        'separate_roadmap' => true, 'recommended' => true,
                                        'recommendation_reason' => 'Most widely used.',
                                    ],
                                    [
                                        'choice_id' => 2, 'title' => 'Vue', 'description' => 'The Vue roadmap.',
                                        'separate_roadmap' => false, 'recommended' => false,
                                        'recommendation_reason' => null,
                                    ],
                                ],
                            ],
                        ],
                    ],
                    'child_roadmaps' => [[
                        'parent_node_id' => 2,
                        'choice_id'      => 1,
                        'choice_title'   => 'React',
                        'roadmap_result' => [
                            'roadmap_topic'     => 'react',
                            'title'             => 'React',
                            'description'       => 'Components, state and hooks.',
                            'freshness_summary' => 'Current.',
                            'nodes' => [
                                [
                                    'type' => 'standard', 'node_id' => 1, 'order' => 1,
                                    'title' => 'React Basics', 'description' => 'JSX and components.',
                                    'prerequisite_node_ids' => [], 'learning_outcomes' => ['Render a component'],
                                ],
                                [
                                    'type' => 'standard', 'node_id' => 2, 'order' => 2,
                                    'title' => 'Hooks', 'description' => 'State and effects.',
                                    'prerequisite_node_ids' => [1], 'learning_outcomes' => ['Use useState'],
                                ],
                            ],
                        ],
                    ]],
                ]],
            ],
        ];
    }

    private function startRun(): RoadmapBuilderRun
    {
        Http::fake([self::START => Http::response([
            'thread_id' => 'build-1', 'status' => 'awaiting_review',
            'review_stage' => 'scope_review',
            'scope_generation_attempts' => 1, 'roadmap_generation_attempts' => 0,
            'data' => ['scope_result' => ['input_topic' => 'web development']],
        ], 201)]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmap-builder-runs', ['topic' => 'web development'])
            ->assertCreated();

        return RoadmapBuilderRun::firstOrFail();
    }

    // -----------------------------------------------------------------
    // Starting
    // -----------------------------------------------------------------

    public function test_a_manager_starts_a_run_from_a_topic_alone(): void
    {
        $run = $this->startRun();

        $this->assertSame('web development', $run->topic);
        $this->assertSame('build-1', $run->thread_id);
        $this->assertSame('scope_review', $run->review_stage);

        // The whole point: a topic string, no graph to extend.
        Http::assertSent(fn ($request) => $request['roadmap_topic'] === 'web development');
    }

    public function test_a_second_run_for_the_same_topic_is_refused(): void
    {
        $this->startRun();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmap-builder-runs', ['topic' => 'Web Development'])
            ->assertStatus(409);

        $this->assertSame(1, RoadmapBuilderRun::count());
    }

    public function test_a_student_may_not_start_a_run(): void
    {
        Http::fake();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/roadmap-builder-runs', ['topic' => 'web development'])
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    // -----------------------------------------------------------------
    // Committing
    // -----------------------------------------------------------------

    public function test_committing_creates_the_whole_three_level_subject(): void
    {
        $run = $this->startRun();

        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        // Level 1: the subject.
        $subject = Roadmap::whereRaw('LOWER(title) = ?', ['web development'])->firstOrFail();
        $this->assertNull($subject->parent_roadmap_id);

        // Level 2: the path.
        $frontend = Roadmap::where('title', 'Frontend')->firstOrFail();
        $this->assertSame($subject->id, $frontend->parent_roadmap_id);

        // Level 3: the choice that asked for its own roadmap.
        $react = Roadmap::where('title', 'React')->firstOrFail();
        $this->assertSame($frontend->id, $react->parent_roadmap_id);

        $run->refresh();
        $this->assertTrue($run->isCommitted());
        $this->assertSame($subject->id, $run->committed_roadmap_id);
    }

    /**
     * Committing writes the structure and asks for no material.
     *
     * It used to queue a learning run per module, so approving one build put
     * the whole subject into generation at once - seventy-odd multi-minute
     * calls to her service, which took the site down and spent the day's
     * quota on modules nobody had chosen to fill in. The manager picks them
     * off the awaiting list instead, one at a time.
     */
    public function test_committing_starts_no_learning_runs(): void
    {
        $run = $this->startRun();

        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        Queue::assertNotPushed(StartLearningRun::class);

        // The modules are there, empty and marked coming-soon - which is what
        // lets the structure publish while its material is still unwritten.
        $modules = VersionNode::whereHas(
            'node',
            fn ($q) => $q->whereIn('title', ['Web Fundamentals', 'Pick a Framework'])
        )->get();

        $this->assertNotEmpty($modules);

        foreach ($modules as $module) {
            $this->assertTrue((bool) $module->coming_soon);
        }
    }

    /** Those same empty modules, offered as the worklist to generate from. */
    public function test_the_committed_modules_are_listed_as_awaiting_material(): void
    {
        $run = $this->startRun();

        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $response = $this->actingAs($manager, 'sanctum')
            ->getJson('/api/modules-awaiting-material')
            ->assertOk();

        $titles = collect($response->json('groups'))
            ->flatMap(fn ($group) => collect($group['modules'])->pluck('title'))
            ->all();

        $this->assertContains('Web Fundamentals', $titles);

        // A module offering choices holds no material of its own - the
        // material belongs to the choices under it - so it is not on the
        // list, exactly as the publish gate treats it.
        $this->assertNotContains('Pick a Framework', $titles);
    }

    /**
     * A failed run is not offered as something to review.
     *
     * It produced nothing: there is no material to read, approve or commit,
     * and it cannot be resumed. What it leaves behind is a module with no
     * material, and that already shows up on the awaiting list - which says
     * what to do about it rather than only that something once went wrong.
     */
    public function test_a_failed_run_leaves_the_review_list_and_the_module_stays_pending(): void
    {
        $run = $this->startRun();

        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $module = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Web Fundamentals'))
            ->firstOrFail();

        \App\Models\NodeLearningWorkflow::create([
            'version_node_id' => $module->id,
            'thread_id'       => 'learn-failed',
            'status'          => 'failed',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->getJson('/api/learning-runs')
            ->assertOk()
            ->assertJsonCount(0, 'runs');

        // And the module it failed on is still waiting to be written.
        $titles = collect(
            $this->actingAs($manager, 'sanctum')
                ->getJson('/api/modules-awaiting-material')->json('groups')
        )->flatMap(fn ($g) => collect($g['modules'])->pluck('title'))->all();

        $this->assertContains('Web Fundamentals', $titles);
    }

    /**
     * A module drops off the list once its run is under way.
     *
     * Otherwise it stays there looking unstarted, and the obvious thing to do
     * about that is press Generate again - which is how one module ends up
     * with two runs writing over each other.
     */
    public function test_a_module_being_generated_leaves_the_awaiting_list(): void
    {
        $run = $this->startRun();

        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $module = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Web Fundamentals'))
            ->firstOrFail();

        Http::fake(['*/learning-workflows' => Http::response([
            'thread_id'    => 'learn-1',
            'status'       => 'awaiting_review',
            'review_stage' => 'tutorial_review',
        ], 201)]);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/version-nodes/{$module->id}/learning-runs")
            ->assertCreated();

        $titles = collect(
            $this->actingAs($manager, 'sanctum')
                ->getJson('/api/modules-awaiting-material')
                ->json('groups')
        )->flatMap(fn ($group) => collect($group['modules'])->pluck('title'))->all();

        $this->assertNotContains('Web Fundamentals', $titles);

        // And a second press is refused outright, so the list going stale in
        // somebody's browser cannot start a duplicate either.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/version-nodes/{$module->id}/learning-runs")
            ->assertStatus(409);
    }

    public function test_the_choice_node_lands_with_its_choices_and_link(): void
    {
        $run = $this->startRun();
        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $pick = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Pick a Framework'))->firstOrFail();

        $this->assertSame('choice', $pick->kind());
        $this->assertSame(2, $pick->choices()->count());

        $reactChoice = $pick->choices()->whereHas('node', fn ($q) => $q->where('title', 'React'))->firstOrFail();
        $vueChoice   = $pick->choices()->whereHas('node', fn ($q) => $q->where('title', 'Vue'))->firstOrFail();

        // separate_roadmap true -> points at the level-3 roadmap.
        $this->assertSame(
            Roadmap::where('title', 'React')->value('id'),
            $reactChoice->links_to_roadmap_id
        );
        $this->assertTrue($reactChoice->is_recommended);
        $this->assertSame('Most widely used.', $reactChoice->recommendation_reason);

        // separate_roadmap false -> a page of its own, no roadmap.
        $this->assertNull($vueChoice->links_to_roadmap_id);
    }

    /** Her prerequisite_node_ids are local to the payload, not our node ids. */
    public function test_prerequisites_are_remapped_to_our_node_ids(): void
    {
        $run = $this->startRun();
        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $fundamentals = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Web Fundamentals'))->firstOrFail();
        $pick         = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Pick a Framework'))->firstOrFail();

        $this->assertDatabaseHas('edges', [
            'from_node_id' => $fundamentals->node_id,
            'to_node_id'   => $pick->node_id,
        ]);
    }

    /** Nothing the AI writes reaches a student without a manager. */
    public function test_everything_committed_is_a_pending_draft(): void
    {
        $run = $this->startRun();
        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        foreach (['Web Development', 'Frontend', 'React'] as $title) {
            $this->assertSame('pending', Roadmap::where('title', $title)->value('review_status'), $title);
        }

        $this->assertDatabaseMissing('roadmap_versions', ['status' => 'Published']);
    }

    public function test_a_run_that_is_not_ready_cannot_be_committed(): void
    {
        $run = $this->startRun();

        $body = $this->readyBody();
        $body['status'] = 'awaiting_review';

        Http::fake([self::THREAD => Http::response($body, 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertStatus(422);

        $this->assertSame(0, Roadmap::where('title', 'Frontend')->count());
    }

    public function test_committing_twice_is_refused(): void
    {
        $run = $this->startRun();
        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertStatus(409);

        $this->assertSame(1, Roadmap::where('title', 'Frontend')->count());
    }

    /** An existing subject gains paths rather than being duplicated. */
    public function test_a_subject_that_already_exists_is_reused(): void
    {
        $existing = Roadmap::create([
            'title'         => 'Web Development',
            'description'   => 'Already here.',
            'review_status' => 'approved',
        ]);

        $run = $this->startRun();
        Http::fake([self::THREAD => Http::response($this->readyBody(), 200)]);
        Queue::fake();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/commit")
            ->assertOk();

        $this->assertSame(1, Roadmap::whereRaw('LOWER(title) = ?', ['web development'])->count());
        $this->assertSame($existing->id, Roadmap::where('title', 'Frontend')->value('parent_roadmap_id'));
    }

    // -----------------------------------------------------------------
    // Review
    // -----------------------------------------------------------------

    public function test_a_review_decision_is_passed_through(): void
    {
        $run = $this->startRun();

        Http::fake([self::THREAD => Http::response([
            'thread_id' => 'build-1', 'status' => 'awaiting_review',
            'review_stage' => 'roadmap_review',
            'scope_generation_attempts' => 1, 'roadmap_generation_attempts' => 1,
        ], 200)]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-builder-runs/{$run->id}/resume", [
                'action' => 'approve',
                'stage'  => 'scope',
            ])
            ->assertOk();

        $this->assertSame('roadmap_review', $run->fresh()->review_stage);

        Http::assertSent(fn ($request) => $request['action'] === 'approve' && $request['stage'] === 'scope');
    }

    /** Her service being down is a 503, not a 500 with a cURL error in it. */
    public function test_an_unreachable_service_is_reported_cleanly(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmap-builder-runs', ['topic' => 'web development'])
            ->assertStatus(503);

        $this->assertSame(0, RoadmapBuilderRun::count());
    }
}
