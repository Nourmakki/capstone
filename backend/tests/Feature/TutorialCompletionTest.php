<?php

namespace Tests\Feature;

use App\Models\Student;
use App\Models\Tutorial;
use App\Models\TutorialCompletion;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Watching a tutorial is something the student says they did.
 *
 * Before this, nothing recorded it: a module could be finished without opening
 * a tutorial, and the module page showed Tutorials as done whenever any
 * existed. Nothing can verify a video was actually watched, so the honest
 * thing is to record the claim and count it.
 */
class TutorialCompletionTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private VersionNode $module;

    protected function setUp(): void
    {
        parent::setUp();

        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $this->module = $nodes[0];
    }

    private function makeTutorial(string $reviewStatus = 'approved'): Tutorial
    {
        return Tutorial::create([
            'version_node_id' => $this->module->id,
            'title'           => 'React in 100 Seconds',
            'url'             => 'https://youtube.com/watch?v=abc',
            'description'     => 'A quick tour.',
            'source_name'     => 'Fireship',
            'review_status'   => $reviewStatus,
        ]);
    }

    public function test_an_unwatched_tutorial_blocks_module_completion(): void
    {
        $user = $this->makeStudent();
        $this->makeTutorial();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $this->module->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString(
            'Watch "React in 100 Seconds"',
            implode(' ', $response->json('blockers'))
        );
    }

    public function test_marking_it_done_unblocks_the_module(): void
    {
        $user     = $this->makeStudent();
        $tutorial = $this->makeTutorial();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete")
            ->assertStatus(200)
            ->assertJson(['completed' => true]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $this->module->id])
            ->assertStatus(200);
    }

    /** Pressing it twice is the same fact, and must not trip the unique index. */
    public function test_marking_it_done_twice_is_harmless(): void
    {
        $user     = $this->makeStudent();
        $tutorial = $this->makeTutorial();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete")->assertStatus(200);
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete")->assertStatus(200);

        $this->assertSame(1, TutorialCompletion::count());
    }

    public function test_it_can_be_undone(): void
    {
        $user     = $this->makeStudent();
        $tutorial = $this->makeTutorial();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete")->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->deleteJson("/api/tutorials/{$tutorial->id}/complete")
            ->assertStatus(200)
            ->assertJson(['completed' => false]);

        $this->assertSame(0, TutorialCompletion::count());

        // And the module is blocked again - undo has to mean something.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $this->module->id])
            ->assertStatus(422);
    }

    /**
     * One student marking a tutorial says nothing about another's progress.
     */
    public function test_one_student_s_mark_does_not_carry_to_another(): void
    {
        $sara     = $this->makeStudent();
        $omar     = $this->makeStudent();
        $tutorial = $this->makeTutorial();

        $this->actingAs($sara, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete")->assertStatus(200);

        $this->actingAs($omar, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $this->module->id])
            ->assertStatus(422);
    }

    /**
     * A pending tutorial is not shown to students, so it must not block them
     * either - otherwise a module becomes uncompletable for a reason the
     * student cannot see or act on.
     */
    public function test_an_unapproved_tutorial_neither_blocks_nor_can_be_marked(): void
    {
        $user     = $this->makeStudent();
        $tutorial = $this->makeTutorial('pending');

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $this->module->id])
            ->assertStatus(200);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete")
            ->assertStatus(404);
    }

    public function test_the_module_page_reports_which_tutorials_are_marked(): void
    {
        $user     = $this->makeStudent();
        $tutorial = $this->makeTutorial();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/version-nodes/{$this->module->id}")
            ->assertStatus(200)
            ->assertJsonPath('tutorials.0.completed', false);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/tutorials/{$tutorial->id}/complete");

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/version-nodes/{$this->module->id}")
            ->assertJsonPath('tutorials.0.completed', true);
    }

    /**
     * The progress line on the roadmap counts the same work the completion
     * rule refuses on, so the two can never disagree.
     */
    public function test_a_tutorial_counts_toward_the_module_s_progress(): void
    {
        $user     = $this->makeStudent();
        $student  = Student::where('user_id', $user->id)->first();
        $tutorial = $this->makeTutorial();

        $this->module->load('tutorials', 'quizzes', 'projects');

        $before = \App\Http\Controllers\Api\ProgressController::requirementProgress($this->module, $student);
        $this->assertSame(['done' => 0, 'total' => 1], $before);

        TutorialCompletion::create([
            'student_id'  => $student->id,
            'tutorial_id' => $tutorial->id,
        ]);

        $after = \App\Http\Controllers\Api\ProgressController::requirementProgress($this->module, $student);
        $this->assertSame(['done' => 1, 'total' => 1], $after);
    }
}
