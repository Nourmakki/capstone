<?php

namespace Tests\Feature;

use App\Models\NodeLearningWorkflow;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\Roadmap;
use App\Models\RoadmapBuilderRun;
use App\Models\Tutorial;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Clearing a run off a list.
 *
 * A run is a receipt: what was asked for, and what it became. Throwing one
 * away has to lose the record of the asking and nothing else - not the
 * roadmap the builder wrote, and not the tutorial, quiz, project and
 * resources a learning run put on a module. Both foreign keys already run
 * that way round, and these say so out loud, because the day one of them is
 * changed to cascade is the day a manager tidying a page deletes a term's
 * work.
 */
class DeleteRunTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    // -----------------------------------------------------------------
    // The roadmap builder
    // -----------------------------------------------------------------

    public function test_a_manager_can_clear_a_builder_run(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);
        $manager = $this->makeManager();

        $run = RoadmapBuilderRun::create([
            'topic'                => 'Web Development',
            'thread_id'            => 'thread-1',
            'status'               => 'committed',
            'committed_roadmap_id' => $version->roadmap_id,
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/roadmap-builder-runs/{$run->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roadmap_builder_runs', ['id' => $run->id]);

        // The whole point: the roadmap it built is still there.
        $this->assertDatabaseHas('roadmaps', ['id' => $version->roadmap_id]);
        $this->assertNotNull(Roadmap::find($version->roadmap_id));
    }

    public function test_a_failed_builder_run_can_be_cleared_too(): void
    {
        $manager = $this->makeManager();

        $run = RoadmapBuilderRun::create([
            'topic'     => 'Nothing came of this',
            'thread_id' => 'thread-2',
            'status'    => 'failed',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/roadmap-builder-runs/{$run->id}")
            ->assertOk();

        $this->assertDatabaseMissing('roadmap_builder_runs', ['id' => $run->id]);
    }

    public function test_only_a_material_manager_may_clear_a_builder_run(): void
    {
        $run = RoadmapBuilderRun::create([
            'topic'     => 'Web Development',
            'thread_id' => 'thread-3',
            'status'    => 'failed',
        ]);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->deleteJson("/api/roadmap-builder-runs/{$run->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('roadmap_builder_runs', ['id' => $run->id]);
    }

    // -----------------------------------------------------------------
    // Learning runs
    // -----------------------------------------------------------------

    public function test_clearing_a_learning_run_leaves_its_material_on_the_module(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);
        $manager = $this->makeManager();

        $versionNode = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $nodes[0]->id)
            ->first();

        $run = NodeLearningWorkflow::create([
            'version_node_id' => $versionNode->id,
            'thread_id'       => 'learning-1',
            'status'          => 'ready_for_commit',
            'committed_at'    => now(),
        ]);

        // What that run wrote.
        $tutorial = Tutorial::create([
            'version_node_id' => $versionNode->id,
            'title'           => 'A tutorial',
            'url'             => 'https://example.com/v',
            'description'     => 'It explains the thing.',
            'source_name'     => 'Some Channel',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        $quiz = Quiz::create([
            'version_node_id' => $versionNode->id,
            'title'           => 'A quiz',
            'review_status'   => 'approved',
        ]);

        $project = Project::create([
            'version_node_id' => $versionNode->id,
            'title'           => 'A project',
            'description'     => 'Build it.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/learning-runs/{$run->id}")
            ->assertOk();

        $this->assertDatabaseMissing('node_learning_workflows', ['id' => $run->id]);

        // Every piece of it survives.
        $this->assertDatabaseHas('tutorials', ['id' => $tutorial->id]);
        $this->assertDatabaseHas('quizzes', ['id' => $quiz->id]);
        $this->assertDatabaseHas('projects', ['id' => $project->id]);
        $this->assertDatabaseHas('version_nodes', ['id' => $versionNode->id]);
    }

    public function test_a_run_still_awaiting_a_decision_can_be_cleared(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);
        $manager = $this->makeManager();

        $versionNode = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $nodes[0]->id)
            ->first();

        $run = NodeLearningWorkflow::create([
            'version_node_id' => $versionNode->id,
            'thread_id'       => 'learning-2',
            'status'          => 'awaiting_review',
        ]);

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/learning-runs/{$run->id}")
            ->assertOk();

        $this->assertDatabaseMissing('node_learning_workflows', ['id' => $run->id]);
    }

    public function test_only_a_material_manager_may_clear_a_learning_run(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $versionNode = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $nodes[0]->id)
            ->first();

        $run = NodeLearningWorkflow::create([
            'version_node_id' => $versionNode->id,
            'thread_id'       => 'learning-3',
            'status'          => 'failed',
        ]);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->deleteJson("/api/learning-runs/{$run->id}")
            ->assertForbidden();

        $this->assertDatabaseHas('node_learning_workflows', ['id' => $run->id]);
    }
}
