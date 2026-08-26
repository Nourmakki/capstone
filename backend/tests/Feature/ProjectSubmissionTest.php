<?php

namespace Tests\Feature;

use App\Models\HumanReviewRequest;
use App\Models\Project;
use App\Models\Student;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class ProjectSubmissionTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private Project $project;

    private function makeProject(string $reviewStatus = 'approved'): Project
    {
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        return $this->project = Project::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Build a To-Do App',
            'description'     => 'Components, state, events.',
            'difficulty'      => 'beginner',
            'review_status'   => $reviewStatus,
        ]);
    }

    private function zip(int $kilobytes = 50): UploadedFile
    {
        return UploadedFile::fake()->create('project.zip', $kilobytes, 'application/zip');
    }

    // -----------------------------------------------------------------
    // Both fields are required
    // -----------------------------------------------------------------

    public function test_a_submission_needs_both_a_repository_and_an_archive(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $user = $this->makeStudent();

        // The repository is what a reviewer reads; the archive is the fixed
        // snapshot of what was handed in. Neither substitutes for the other.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/projects/{$this->project->id}/submit", [
                'github_url' => 'https://github.com/sara/todo',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');

        $this->actingAs($user, 'sanctum')
            ->post("/api/projects/{$this->project->id}/submit", [
                'file' => $this->zip(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('github_url');

        $this->assertSame(0, Submission::count());
    }

    public function test_the_repository_link_must_be_a_url_with_a_scheme(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->post("/api/projects/{$this->project->id}/submit", [
                'github_url' => 'github.com/sara/todo',
                'file'       => $this->zip(),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('github_url');
    }

    public function test_a_non_zip_upload_is_refused(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->post("/api/projects/{$this->project->id}/submit", [
                'github_url' => 'https://github.com/sara/todo',
                'file'       => UploadedFile::fake()->create('notes.txt', 10, 'text/plain'),
            ], ['Accept' => 'application/json'])
            ->assertStatus(422)
            ->assertJsonValidationErrors('file');
    }

    public function test_a_valid_submission_is_stored_with_its_file(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $this->actingAs($user, 'sanctum')
            ->post("/api/projects/{$this->project->id}/submit", [
                'github_url' => 'https://github.com/sara/todo',
                'file'       => $this->zip(),
                'notes'      => 'Ran out of time on the empty state.',
            ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('submission.has_file', true)
            ->assertJsonPath('submission.status', 'submitted')
            ->assertJsonPath('submission.ai_score', null);

        $submission = Submission::first();

        $this->assertSame($student->id, $submission->student_id);
        $this->assertNotNull($submission->file_path);
        Storage::assertExists($submission->file_path);
    }

    // -----------------------------------------------------------------
    // Downloading
    // -----------------------------------------------------------------

    public function test_only_the_owner_and_a_professor_may_download_a_submission(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $owner = $this->makeStudent();

        $this->actingAs($owner, 'sanctum')->post("/api/projects/{$this->project->id}/submit", [
            'github_url' => 'https://github.com/sara/todo',
            'file'       => $this->zip(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $submission = Submission::first();
        $url = "/api/submissions/{$submission->id}/download";

        $this->actingAs($owner, 'sanctum')->get($url)->assertOk();
        $this->actingAs($this->makeProfessor(), 'sanctum')->get($url)->assertOk();

        // Grading is the professor's job, so these two are shut out.
        $this->actingAs($this->makeManager(), 'sanctum')->getJson($url)->assertForbidden();
        $this->actingAs($this->makeAdmin(), 'sanctum')->getJson($url)->assertForbidden();

        // Another student must not read someone else's work.
        $this->actingAs($this->makeStudent(), 'sanctum')->getJson($url)->assertForbidden();

        // actingAs leaves a resolved user on the guard, and the whole test
        // shares one application instance - without this the "anonymous"
        // request below is still authenticated as the student above.
        $this->app['auth']->forgetGuards();

        $this->getJson($url)->assertUnauthorized();
    }

    public function test_downloading_a_submission_with_no_file_is_a_clean_404(): void
    {
        $this->makeProject();
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $submission = Submission::create([
            'project_id' => $this->project->id,
            'student_id' => $student->id,
            'github_url' => 'https://github.com/sara/todo',
            'status'     => 'submitted',
        ]);

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/submissions/{$submission->id}/download")
            ->assertNotFound();
    }

    // -----------------------------------------------------------------
    // Requesting a human review
    // -----------------------------------------------------------------

    public function test_a_student_requests_a_review_of_their_own_submission_once(): void
    {
        $this->makeProject();
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $submission = Submission::create([
            'project_id' => $this->project->id,
            'student_id' => $student->id,
            'github_url' => 'https://github.com/sara/todo',
            'status'     => 'submitted',
        ]);

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/request-review")
            ->assertCreated();

        // Asking twice must not queue the same work for two professors.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/request-review")
            ->assertStatus(409);

        $this->assertSame(1, HumanReviewRequest::count());
        $this->assertNull(HumanReviewRequest::first()->professor_id);
    }

    public function test_a_student_cannot_request_a_review_of_someone_elses_submission(): void
    {
        $this->makeProject();
        $owner = $this->makeStudent();
        $student = Student::where('user_id', $owner->id)->first();

        $submission = Submission::create([
            'project_id' => $this->project->id,
            'student_id' => $student->id,
            'github_url' => 'https://github.com/sara/todo',
            'status'     => 'submitted',
        ]);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson("/api/submissions/{$submission->id}/request-review")
            ->assertForbidden();

        $this->assertSame(0, HumanReviewRequest::count());
    }

    public function test_requesting_a_review_of_a_deleted_submission_explains_itself(): void
    {
        $user = $this->makeStudent();

        // Route model binding would answer this with a stack-trace string.
        $this->actingAs($user, 'sanctum')
            ->postJson('/api/submissions/9999/request-review')
            ->assertNotFound()
            ->assertJsonPath('message', 'That submission no longer exists. Refresh the page.');
    }

    // -----------------------------------------------------------------
    // Files do not outlive their rows
    // -----------------------------------------------------------------

    public function test_deleting_a_submission_removes_its_archive(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')->post("/api/projects/{$this->project->id}/submit", [
            'github_url' => 'https://github.com/sara/todo',
            'file'       => $this->zip(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $submission = Submission::first();
        $path = $submission->file_path;

        Storage::assertExists($path);

        $submission->delete();

        Storage::assertMissing($path);
    }

    public function test_deleting_a_user_removes_their_submission_archives(): void
    {
        Storage::fake('local');
        $this->makeProject();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')->post("/api/projects/{$this->project->id}/submit", [
            'github_url' => 'https://github.com/sara/todo',
            'file'       => $this->zip(),
        ], ['Accept' => 'application/json'])->assertCreated();

        $path = Submission::first()->file_path;

        Storage::assertExists($path);

        // students.user_id cascades in the database, so the Submission
        // deleting hook never fires - User::booted() has to clean up first.
        $user->delete();

        Storage::assertMissing($path);
        $this->assertSame(0, Submission::count());
    }

    // -----------------------------------------------------------------
    // Access
    // -----------------------------------------------------------------

    public function test_an_unapproved_project_is_not_reachable_by_guessing_its_id(): void
    {
        $this->makeProject('pending');

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->getJson("/api/projects/{$this->project->id}")
            ->assertNotFound();
    }
}