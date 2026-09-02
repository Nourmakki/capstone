<?php

namespace Tests\Feature;

use App\Models\NodeLearningWorkflow;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\RoadmapAiWorkflow;
use App\Models\Tutorial;
use App\Models\VersionNode;
use App\Services\AiRoadmapCommitService;
use App\Services\VersionPublishRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Validation\ValidationException;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * A module announced before its material exists.
 *
 * The trend scan proposes a module, a manager commits it, and the new version
 * can go live at once with the module visible but not yet enterable. The
 * learning workflow writes its material in the background; the mark comes off
 * when that material is approved.
 */
class ComingSoonTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    /** A committed trend proposal that invents one module. */
    private function commitProposal(): array
    {
        ['roadmap' => $roadmap, 'version' => $version, 'nodes' => $nodes] =
            $this->makeRoadmap(['Basics'], 'Published');

        $workflow = RoadmapAiWorkflow::create([
            'roadmap_version_id' => $version->id,
            'thread_id'          => 'trend-1',
            'status'             => 'ready_for_commit',
        ]);

        $newId = $nodes[0]->id + 500;

        app(AiRoadmapCommitService::class)->commit($workflow, [
            'node_suggestions_with_ids' => [[
                'id' => $newId, 'title' => 'Zero Trust',
                'description' => 'Never trust, always verify.', 'difficulty' => 'advanced',
            ]],
            'placement_suggestions' => [[
                'suggested_node_id'            => $newId,
                'direct_prerequisite_node_ids' => [$nodes[0]->id],
                'direct_successor_node_ids'    => [],
            ]],
        ]);

        $draft = $workflow->fresh()->committedVersion ?? \App\Models\RoadmapVersion::orderByDesc('id')->first();

        $newNode = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Zero Trust'))
            ->where('roadmap_version_id', $draft->id)
            ->firstOrFail();

        return ['version' => $draft, 'node' => $newNode, 'carried' => $nodes[0]];
    }

    // -----------------------------------------------------------------
    // The mark goes on
    // -----------------------------------------------------------------

    public function test_a_committed_trend_module_is_marked_coming_soon(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['node' => $node, 'carried' => $carried] = $this->commitProposal();

        $this->assertTrue($node->coming_soon, 'The new module should be coming soon.');

        // Modules carried over from the previous version are untouched.
        $carriedNode = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Basics'))
            ->orderByDesc('id')
            ->first();

        $this->assertFalse($carriedNode->coming_soon);
    }

    /** The whole point of the chain: its material starts being written at once. */
    public function test_committing_starts_a_learning_run_for_the_new_module(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['node' => $node] = $this->commitProposal();

        $this->assertDatabaseHas('node_learning_workflows', [
            'version_node_id' => $node->id,
            'thread_id'       => 'learn-1',
        ]);
    }

    /**
     * A manager has just approved a roadmap. An unreachable AI service should
     * not undo that - the modules stay coming-soon and startable by hand.
     */
    public function test_an_unreachable_ai_service_does_not_lose_the_commit(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        ['node' => $node] = $this->commitProposal();

        $this->assertTrue($node->coming_soon);
        $this->assertSame(0, NodeLearningWorkflow::count());
    }

    // -----------------------------------------------------------------
    // What it means while it is on
    // -----------------------------------------------------------------

    /** Visible on the rail, but there is nothing in it to do yet. */
    public function test_a_coming_soon_module_cannot_be_entered(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['node' => $node] = $this->commitProposal();

        $response = $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson("/api/version-nodes/{$node->id}")
            ->assertOk();

        $this->assertTrue($response->json('coming_soon'));
        $this->assertFalse($response->json('is_unlocked'));
    }

    /**
     * The one case where an empty module may go live. A module nobody filled
     * in is still refused - that is the difference the stored flag buys.
     */
    public function test_a_version_can_publish_with_a_coming_soon_module(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['version' => $version, 'node' => $node] = $this->commitProposal();

        // Give the carried-over module what the gate wants.
        foreach (VersionNode::where('roadmap_version_id', $version->id)->inRail()->get() as $module) {
            if ($module->id === $node->id) {
                continue;
            }

            Tutorial::create(['version_node_id' => $module->id, 'title' => 'T', 'url' => 'https://x.test',
                'description' => 'd', 'source_name' => 's', 'review_status' => 'approved']);
            Quiz::create(['version_node_id' => $module->id, 'title' => 'Q', 'score' => 0, 'review_status' => 'approved']);
            Project::create(['version_node_id' => $module->id, 'title' => 'P', 'description' => 'd',
                'difficulty' => 'beginner', 'review_status' => 'approved']);
        }

        // Coming-soon module has nothing, and the version still publishes.
        (new VersionPublishRules)->assertReadyToPublish($version->fresh());

        // Take the mark off and the same version is refused.
        $node->update(['coming_soon' => false]);

        $this->expectException(ValidationException::class);
        (new VersionPublishRules)->assertReadyToPublish($version->fresh());
    }

    // -----------------------------------------------------------------
    // Committing puts it in front of students
    // -----------------------------------------------------------------

    /**
     * Announcing is the point. A committed proposal goes live so a student
     * sees the module arriving; coming-soon is what stops them entering it.
     * Left as a draft, nobody knew the roadmap had grown until the material
     * was finished - which is when the mark comes off anyway.
     */
    public function test_committing_puts_the_new_module_in_front_of_students(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['version' => $source, 'nodes' => $nodes, 'versionNodes' => $versionNodes] =
            $this->makeRoadmap(['Basics'], 'Published');

        // The roadmap has to be publishable in the first place. Material
        // hangs off the version node, and the cloner carries it forward, so
        // the copy in the committed version has it too.
        $this->giveMaterial($versionNodes[0]);

        $workflow = RoadmapAiWorkflow::create([
            'roadmap_version_id' => $source->id,
            'thread_id'          => 'trend-live',
            'status'             => 'ready_for_commit',
        ]);

        $newId = $nodes[0]->id + 500;

        $version = app(AiRoadmapCommitService::class)->commit($workflow, [
            'node_suggestions_with_ids' => [[
                'id' => $newId, 'title' => 'Zero Trust',
                'description' => 'Never trust, always verify.', 'difficulty' => 'advanced',
            ]],
            'placement_suggestions' => [[
                'suggested_node_id'            => $newId,
                'direct_prerequisite_node_ids' => [$nodes[0]->id],
                'direct_successor_node_ids'    => [],
            ]],
        ])->fresh();

        $this->assertSame('Published', $version->status);
        $this->assertSame('approved', $version->review_status);

        // Only one version of a roadmap is served at a time.
        $this->assertSame('Archived', $source->fresh()->status);

        $new = VersionNode::whereHas('node', fn ($q) => $q->where('title', 'Zero Trust'))
            ->where('roadmap_version_id', $version->id)
            ->firstOrFail();

        // Visible, and still not enterable: that is the whole arrangement.
        $this->assertTrue($new->coming_soon);

        $response = $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson("/api/version-nodes/{$new->id}")
            ->assertOk();

        $this->assertTrue($response->json('coming_soon'));
        $this->assertFalse($response->json('is_unlocked'));
    }

    /**
     * Roadmaps published before the readiness rule existed would not satisfy
     * it today. Going live is skipped for those rather than failing - the
     * commit happens inside a transaction, so throwing would undo it and
     * committing onto such a roadmap would stop working altogether.
     */
    public function test_a_roadmap_that_cannot_publish_leaves_the_commit_a_draft(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        // commitProposal builds a roadmap whose module has no material.
        ['version' => $version, 'node' => $node] = $this->commitProposal();

        $this->assertSame('Draft', $version->fresh()->status);
        $this->assertTrue($node->coming_soon, 'The commit still happened.');
    }

    /** Everything the publish gate asks of one module. */
    private function giveMaterial(VersionNode $module): void
    {
        Tutorial::create(['version_node_id' => $module->id, 'title' => 'T', 'url' => 'https://x.test',
            'description' => 'd', 'source_name' => 's', 'review_status' => 'approved']);
        Quiz::create(['version_node_id' => $module->id, 'title' => 'Q', 'score' => 0, 'review_status' => 'approved']);
        Project::create(['version_node_id' => $module->id, 'title' => 'P', 'description' => 'd',
            'difficulty' => 'beginner', 'review_status' => 'approved']);
    }

    // -----------------------------------------------------------------
    // The mark comes off
    // -----------------------------------------------------------------

    public function test_approving_the_material_lifts_the_mark(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['node' => $node] = $this->commitProposal();

        $tutorial = Tutorial::create(['version_node_id' => $node->id, 'title' => 'T', 'url' => 'https://x.test',
            'description' => 'd', 'source_name' => 's', 'review_status' => 'pending']);
        $quiz = Quiz::create(['version_node_id' => $node->id, 'title' => 'Q', 'score' => 0, 'review_status' => 'pending']);
        $project = Project::create(['version_node_id' => $node->id, 'title' => 'P', 'description' => 'd',
            'difficulty' => 'beginner', 'review_status' => 'pending']);

        $manager = $this->makeManager();

        // One or two of the three is not a module a student can work through,
        // so the mark stays on until all of them are approved.
        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/material/tutorials/{$tutorial->id}/approve")->assertOk();
        $this->assertTrue($node->fresh()->coming_soon);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/material/quizzes/{$quiz->id}/approve")->assertOk();
        $this->assertTrue($node->fresh()->coming_soon);

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/material/projects/{$project->id}/approve")->assertOk();

        $this->assertFalse($node->fresh()->coming_soon, 'All three approved - the mark should be gone.');
    }

    /** Once lifted, the module behaves like any other. */
    public function test_the_module_is_enterable_once_the_mark_is_lifted(): void
    {
        Http::fake(['*' => Http::response(['thread_id' => 'learn-1', 'status' => 'awaiting_review'], 201)]);

        ['node' => $node] = $this->commitProposal();

        $node->update(['coming_soon' => false]);

        $response = $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson("/api/version-nodes/{$node->id}")
            ->assertOk();

        $this->assertFalse($response->json('coming_soon'));
    }
}
