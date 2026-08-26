<?php

namespace Tests\Feature;

use App\Models\CompletedNode;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\Student;
use App\Models\Submission;
use App\Models\UserRoadmapProgress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class ProgressGatingTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    /**
     * Marks a module finished, bypassing the gate. Used to satisfy the
     * prerequisites of a later module without asserting on this one.
     */
    private function markComplete($student, $versionNode): void
    {
        $progress = UserRoadmapProgress::firstOrCreate([
            'student_id'         => $student->id,
            'roadmap_version_id' => $versionNode->roadmap_version_id,
        ], ['status' => 'in_progress']);

        CompletedNode::firstOrCreate([
            'user_roadmap_progress_id' => $progress->id,
            'node_id'                  => $versionNode->node_id,
        ]);
    }

    // -----------------------------------------------------------------
    // Prerequisites
    // -----------------------------------------------------------------

    public function test_the_first_module_is_unlocked_and_later_ones_are_not(): void
    {
        $user = $this->makeStudent();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components', 'State']);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/version-nodes/{$nodes[0]->id}")
            ->assertOk()
            ->assertJsonPath('is_unlocked', true);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/version-nodes/{$nodes[1]->id}")
            ->assertOk()
            ->assertJsonPath('is_unlocked', false);
    }

    public function test_a_module_cannot_be_completed_before_its_prerequisite(): void
    {
        $user = $this->makeStudent();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[1]->id]);

        $response->assertStatus(422)
            ->assertJsonPath('message', 'This module is not finished yet.');

        $this->assertStringContainsString('Basics', implode(' ', $response->json('blockers')));
        $this->assertSame(0, CompletedNode::count());
    }

    public function test_completing_a_prerequisite_unlocks_the_next_module(): void
    {
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id])
            ->assertOk();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/version-nodes/{$nodes[1]->id}")
            ->assertJsonPath('is_unlocked', true);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[1]->id])
            ->assertOk();

        $this->assertSame(2, CompletedNode::count());
    }

    // -----------------------------------------------------------------
    // Quizzes block completion
    // -----------------------------------------------------------------

    public function test_a_module_with_an_untaken_quiz_cannot_be_completed(): void
    {
        $user = $this->makeStudent();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        Quiz::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Basics Quiz',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Take the quiz', implode(' ', $response->json('blockers')));
    }

    public function test_a_failing_quiz_score_still_blocks_completion(): void
    {
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $quiz = Quiz::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Basics Quiz',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        Submission::create([
            'quiz_id'    => $quiz->id,
            'student_id' => $student->id,
            'score'      => 40,
            'status'     => 'submitted',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('your best score is 40%', implode(' ', $response->json('blockers')));
    }

    public function test_the_best_attempt_counts_not_the_latest(): void
    {
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $quiz = Quiz::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Basics Quiz',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        // Passed once, then did worse on a retake. The pass should stand.
        foreach ([90, 30] as $score) {
            Submission::create([
                'quiz_id'    => $quiz->id,
                'student_id' => $student->id,
                'score'      => $score,
                'status'     => 'submitted',
            ]);
        }

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id])
            ->assertOk();
    }

    // -----------------------------------------------------------------
    // Projects block completion
    // -----------------------------------------------------------------

    public function test_an_unsubmitted_project_blocks_completion(): void
    {
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $project = Project::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Build a To-Do App',
            'description'     => 'Make something.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id]);

        $response->assertStatus(422);
        $this->assertStringContainsString('Submit the project', implode(' ', $response->json('blockers')));

        Submission::create([
            'project_id' => $project->id,
            'student_id' => $student->id,
            'status'     => 'submitted',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id])
            ->assertOk();
    }

    public function test_every_blocker_is_reported_at_once(): void
    {
        $user = $this->makeStudent();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        Quiz::create([
            'version_node_id' => $nodes[1]->id,
            'title'           => 'Components Quiz',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        Project::create([
            'version_node_id' => $nodes[1]->id,
            'title'           => 'Build Components',
            'description'     => 'Make something.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        // Prerequisite, quiz and project are all outstanding. Reporting one
        // at a time would make finishing a module a guessing game.
        $blockers = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[1]->id])
            ->assertStatus(422)
            ->json('blockers');

        $this->assertCount(3, $blockers);
    }

    // -----------------------------------------------------------------
    // Ownership
    // -----------------------------------------------------------------

    public function test_progress_is_recorded_against_the_token_holder(): void
    {
        $sara = $this->makeStudent();
        $omar = $this->makeStudent();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $this->actingAs($sara, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id])
            ->assertOk();

        $omarStudent = Student::where('user_id', $omar->id)->first();

        // One student finishing a module must not advance another.
        $this->assertSame(0, UserRoadmapProgress::where('student_id', $omarStudent->id)->count());
        $this->assertSame(1, CompletedNode::count());
    }

    public function test_completing_the_same_module_twice_does_not_duplicate_progress(): void
    {
        $user = $this->makeStudent();
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        foreach (range(1, 3) as $ignored) {
            $this->actingAs($user, 'sanctum')
                ->postJson('/api/progress/complete-node', ['version_node_id' => $nodes[0]->id])
                ->assertOk();
        }

        $this->assertSame(1, CompletedNode::count());
        $this->assertSame(1, UserRoadmapProgress::count());
    }
}