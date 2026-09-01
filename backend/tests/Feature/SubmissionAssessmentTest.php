<?php

namespace Tests\Feature;

use App\Models\NodeLearningWorkflow;
use App\Models\Project;
use App\Models\Submission;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * AI grading of a project submission - the third of her workflows.
 *
 * Her service is faked throughout: these assert the contract we send her and
 * what we do with what comes back, not that her agents work.
 */
class SubmissionAssessmentTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private const URL = 'http://127.0.0.1:8001/project-assessment-workflows*';

    /** A submission on a module that has a committed learning run. */
    private function makeSubmission(array $attributes = [], bool $withWorkflow = true): Submission
    {
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $project = Project::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Build a component',
            'description'     => 'A small build.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        if ($withWorkflow) {
            NodeLearningWorkflow::create([
                'version_node_id' => $nodes[0]->id,
                'thread_id'       => 'thread-abc-123',
                'status'          => 'ready_for_commit',
            ]);
        }

        $student = $this->makeStudent();

        return Submission::create(array_merge([
            'project_id' => $project->id,
            'student_id' => $student->student->id ?? \App\Models\Student::where('user_id', $student->id)->value('id'),
            'github_url' => 'https://github.com/someone/project',
            'status'     => 'submitted',
        ], $attributes));
    }

    /** A finished response shaped like her ProjectAssessmentWorkflowResponse. */
    private function readyBody(float $total = 87.5): array
    {
        return [
            'thread_id' => 'assess-1',
            'status'    => 'ready_for_commit',
            'data'      => [
                'learning_thread_id' => 'thread-abc-123',
                'submission'         => ['submission_type' => 'github'],
                'assessment_score'   => [
                    'base_score'     => 80.0,
                    'bonus_score'    => 7,
                    'total_score'    => $total,
                    'max_base_score' => 100,
                    'max_bonus_score' => 10,
                ],
                'project_assessment_result' => [
                    'node_id'                => 1,
                    'tutorial_resource_id'   => 'res-1',
                    'activity_type'          => 'project',
                    'criterion_assessments'  => [[
                        'criterion_id' => 'c1',
                        'criterion'    => 'Component renders',
                        'status'       => 'met',
                        'evidence'     => 'App.js line 12',
                        'feedback'     => 'Works as asked.',
                    ]],
                    'meaningful_extra_features' => ['score' => 3, 'evidence' => [], 'explanation' => 'Added tests.'],
                    'creativity_and_innovation' => ['score' => 4, 'evidence' => [], 'explanation' => 'Nice styling.'],
                    'constraint_violations'     => [],
                    'strengths'                 => ['Clean structure'],
                    'improvements'              => ['Add error handling'],
                    'overall_feedback'          => 'A solid submission.',
                ],
            ],
        ];
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function test_a_graded_submission_records_the_score_and_feedback(): void
    {
        Http::fake([self::URL => Http::response($this->readyBody(), 200)]);

        $submission = $this->makeSubmission();

        $response = $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        $submission->refresh();

        $this->assertSame(88, $submission->ai_score);          // 87.5 rounded
        $this->assertSame('ai_reviewed', $submission->status);
        $this->assertNotNull($submission->ai_reviewed_at);
        $this->assertStringContainsString('A solid submission.', $submission->ai_feedback);
        $this->assertStringContainsString('Clean structure', $submission->ai_feedback);
        $this->assertStringContainsString('Add error handling', $submission->ai_feedback);

        // The criterion detail has nowhere to live in a column, so it has to
        // survive in ai_metadata or it is lost. Cast to array on the model,
        // so storing an encoded string here would double-encode it.
        $this->assertIsArray($submission->ai_metadata);
        $this->assertSame(
            'Component renders',
            $submission->ai_metadata['assessment']['criterion_assessments'][0]['criterion']
        );
        $this->assertSame('Component renders', $response->json('assessment.breakdown.criteria.0.criterion'));

        // Her exact figure survives beside the rounded column.
        $this->assertSame(87.5, $response->json('assessment.breakdown.total_score'));
    }

    /**
     * Her base is 0-100 with up to 10 bonus, so a total of 110 is legal.
     * Clamping it here would silently discard the bonus she calculated.
     */
    public function test_a_bonus_score_above_one_hundred_is_kept(): void
    {
        Http::fake([self::URL => Http::response($this->readyBody(110.0), 200)]);

        $submission = $this->makeSubmission();

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        $this->assertSame(110, $submission->refresh()->ai_score);
    }

    /**
     * When she sends an assessment with no score block, AiGradingService
     * recomputes it from her STATUS_SCORE_VALUES rather than storing nothing.
     * Two criteria, one met (1.0) and one partially met (0.5), is 75 base;
     * plus 3 + 4 bonus is 82.
     */
    public function test_a_missing_score_block_is_recomputed_with_her_status_values(): void
    {
        $body = $this->readyBody();
        unset($body['data']['assessment_score']);

        $body['data']['project_assessment_result']['criterion_assessments'][] = [
            'criterion_id' => 'c2',
            'criterion'    => 'Handles errors',
            'status'       => 'partially_met',
            'evidence'     => 'No try/catch',
            'feedback'     => 'Partly handled.',
        ];

        Http::fake([self::URL => Http::response($body, 200)]);

        $submission = $this->makeSubmission();

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        $this->assertSame(82, $submission->refresh()->ai_score);
    }

    /** A professor's own grade outranks the AI's and must not be overwritten. */
    public function test_a_human_reviewed_submission_keeps_its_status_and_score(): void
    {
        Http::fake([self::URL => Http::response($this->readyBody(), 200)]);

        $submission = $this->makeSubmission(['status' => 'human_reviewed', 'score' => 95]);

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        $submission->refresh();

        $this->assertSame('human_reviewed', $submission->status);
        $this->assertSame(95, $submission->score);
        $this->assertSame(95, $submission->finalScore());   // the human still wins
        $this->assertSame(88, $submission->ai_score);       // but the AI is recorded
    }

    // -----------------------------------------------------------------
    // The contract we send her
    // -----------------------------------------------------------------

    public function test_a_repository_only_hand_in_is_sent_as_github(): void
    {
        Http::fake([self::URL => Http::response($this->readyBody(), 200)]);

        $submission = $this->makeSubmission(['github_url' => 'https://github.com/a/b']);

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $this->multipartFields($request);

            $this->assertSame('github', $body['submission_type']);
            $this->assertSame('thread-abc-123', $body['learning_thread_id']);
            $this->assertSame('https://github.com/a/b', $body['repository_url']);

            return true;
        });
    }

    /**
     * Her validator raises on a repository_url that is merely *present* when
     * the type is "file", so the field has to be omitted, not sent null.
     */
    public function test_a_file_only_hand_in_omits_the_repository_url_entirely(): void
    {
        Http::fake([self::URL => Http::response($this->readyBody(), 200)]);
        Storage::fake('local');

        $submission = $this->makeSubmission(['github_url' => null]);
        $submission->update([
            'file_path' => UploadedFile::fake()->create('solution.py', 4)->store('submissions'),
        ]);

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        Http::assertSent(function ($request) {
            $body = $this->multipartFields($request);

            $this->assertSame('file', $body['submission_type']);
            $this->assertArrayNotHasKey('repository_url', $body);

            return true;
        });
    }

    public function test_a_file_and_repository_hand_in_is_sent_as_both(): void
    {
        Http::fake([self::URL => Http::response($this->readyBody(), 200)]);
        Storage::fake('local');

        $submission = $this->makeSubmission(['github_url' => 'https://github.com/a/b']);
        $submission->update([
            'file_path' => UploadedFile::fake()->create('solution.zip', 4)->store('submissions'),
        ]);

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertOk();

        Http::assertSent(function ($request) {
            $this->assertSame('file and github', $this->multipartFields($request)['submission_type']);

            return true;
        });
    }

    // -----------------------------------------------------------------
    // Refusals
    // -----------------------------------------------------------------

    /**
     * Her assessment grades against the material a learning run produced, so
     * without one there are no criteria to apply.
     */
    public function test_a_module_with_no_learning_run_is_refused_before_calling_her(): void
    {
        Http::fake();

        $submission = $this->makeSubmission([], withWorkflow: false);

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    public function test_a_submission_with_nothing_attached_is_refused(): void
    {
        Http::fake();

        $submission = $this->makeSubmission(['github_url' => null]);

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /** Her workflow reports its own failure with a 200 and status "failed". */
    public function test_a_failed_workflow_is_not_recorded_as_a_grade(): void
    {
        Http::fake([self::URL => Http::response([
            'thread_id' => 'x', 'status' => 'failed', 'data' => null,
            'error' => 'Could not clone the repository.',
        ], 200)]);

        $submission = $this->makeSubmission();

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertStatus(422);

        $submission->refresh();

        $this->assertNull($submission->ai_score);
        $this->assertNull($submission->ai_reviewed_at);
        $this->assertSame('submitted', $submission->status);
    }

    public function test_an_unreachable_service_does_not_change_the_submission(): void
    {
        Http::fake([self::URL => Http::response('', 500)]);

        $submission = $this->makeSubmission();

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertStatus(422);

        $this->assertNull($submission->refresh()->ai_score);
    }

    public function test_a_student_may_not_trigger_an_assessment(): void
    {
        Http::fake();

        $submission = $this->makeSubmission();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/assess")
            ->assertStatus(403);

        Http::assertNothingSent();
    }

    public function test_reading_an_assessment_that_never_ran_is_a_404(): void
    {
        $submission = $this->makeSubmission();

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->getJson("/api/submissions/{$submission->id}/assessment")
            ->assertStatus(404);
    }

    /** Pull the multipart fields back out of a faked request. */
    private function multipartFields($request): array
    {
        $fields = [];

        foreach ($request->data() as $part) {
            if (isset($part['name']) && $part['name'] !== 'submission_file') {
                $fields[$part['name']] = $part['contents'];
            }
        }

        return $fields;
    }
}
