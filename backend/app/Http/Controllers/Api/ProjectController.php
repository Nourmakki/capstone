<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitProjectRequest;
use App\Models\HumanReviewRequest;
use App\Models\Project;
use App\Models\Student;
use App\Models\Submission;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class ProjectController extends Controller
{
    public function show(Request $request, Project $project)
    {
        // An unapproved project must not be reachable by guessing its id.
        $this->requireApproved($request, $project);

        // A material manager can read the brief; only students have
        // their own submissions attached.
        $student = Student::where('user_id', $request->user()->id)->first();

        $project->load('versionNode.node');

        $submissions = $student
            ? Submission::where('project_id', $project->id)
                ->where('student_id', $student->id)
                ->orderByDesc('created_at')
                ->get()
                ->map(fn ($s) => $this->submissionPayload($s))
            : collect();

        return response()->json([
            'id'              => $project->id,
            'title'           => $project->title,
            'description'     => $project->description,
            'difficulty'      => $project->difficulty,
            'github_url'      => $project->github_url,
            'version_node_id' => $project->version_node_id,
            'node_title'      => $project->versionNode->node->title ?? null,
            'submissions'     => $submissions,
        ]);
    }

    public function submit(SubmitProjectRequest $request, Project $project)
    {
        $this->requireApproved($request, $project);

        $student = $this->studentFor($request);

        // Private disk - served only through the download route below.
        $path = $request->hasFile('file')
            ? $this->storeSubmission($request->file('file'))
            : null;

        $submission = Submission::create([
            'project_id'  => $project->id,
            'student_id'  => $student->id,
            'github_url'  => $request->validated()['github_url'] ?? null,
            'file_path'   => $path,
            'content'     => $request->validated()['notes'] ?? null,
            'status'      => 'submitted',
        ]);

        return response()->json([
            'message'    => 'Project submitted.',
            'submission' => $this->submissionPayload($submission),
        ], 201);
    }

    /**
     * Store an upload under a random name that keeps the extension the student
     * actually submitted.
     *
     * Laravel's store() names the file from the *detected* content type, and
     * every text format we accept reports as text/plain - so .py, .sql, .md and
     * .ipynb all landed on disk as .txt. The AI grader dispatches on the
     * extension, which meant validating the format and then discarding the one
     * fact the grader needs.
     *
     * The name itself stays random rather than the student's: two people
     * submitting solution.py must not collide, and a filename off the wire is
     * not something to write to disk unexamined. Only the extension is carried
     * over, and SubmitProjectRequest has already pinned that to ACCEPTED.
     */
    private function storeSubmission(\Illuminate\Http\UploadedFile $file): string
    {
        $extension = strtolower($file->getClientOriginalExtension());

        return $file->storeAs('submissions', Str::random(40) . '.' . $extension);
    }

    /**
     * A student asks for a human review of their submission.
     * professor_id stays null until a professor claims it.
     */
    public function requestReview(Request $request, $submissionId)
    {
        // Looked up by hand rather than route-model-bound: binding answers a
        // stale id with "No query results for model [App\Models\Submission]",
        // which is a stack-trace string, not something to show a student.
        $submission = Submission::find($submissionId);

        if (! $submission) {
            return response()->json([
                'message' => 'That submission no longer exists. Refresh the page.',
            ], 404);
        }

        $student = $this->studentFor($request);

        if ($submission->student_id !== $student->id) {
            return response()->json(['message' => 'This is not your submission.'], 403);
        }

        $existing = HumanReviewRequest::where('submission_id', $submission->id)
            ->where('status', 'pending')
            ->first();

        if ($existing) {
            return response()->json([
                'message' => 'A review has already been requested for this submission.',
            ], 409);
        }

        $review = HumanReviewRequest::create([
            'submission_id' => $submission->id,
            'professor_id'  => null,
            'status'        => 'pending',
        ]);

        return response()->json([
            'message'   => 'Review requested.',
            'review_id' => $review->id,
        ], 201);
    }

    /**
     * The student who submitted it, and the professors who grade it.
     */
    public function download(Request $request, Submission $submission)
    {
        $user = $request->user();

        $isStaff = $user->role === 'Professor';
        $student = Student::where('user_id', $user->id)->first();
        $isOwner = $student && $submission->student_id === $student->id;

        if (! $isStaff && ! $isOwner) {
            return response()->json(['message' => 'Forbidden.'], 403);
        }

        if (! $submission->file_path || ! Storage::exists($submission->file_path)) {
            return response()->json(['message' => 'No file on this submission.'], 404);
        }

        return Storage::download($submission->file_path);
    }

    private function submissionPayload(Submission $submission): array
    {
        return [
            'id'             => $submission->id,
            'github_url'     => $submission->github_url,
            'has_file'       => (bool) $submission->file_path,
            'notes'          => $submission->content,
            'status'         => $submission->status,
            'score'          => $submission->score,
            'comments'       => $submission->comments,
            // Stay null until the AI service fills them; the UI is null-safe.
            'ai_score'       => $submission->ai_score,
            'ai_feedback'    => $submission->ai_feedback,
            'final_score'    => $submission->finalScore(),
            'human_reviewed' => $submission->status === 'human_reviewed',
            'created_at'     => $submission->created_at,
        ];
    }

    private function studentFor(Request $request): Student
    {
        return Student::where('user_id', $request->user()->id)->firstOrFail();
    }

    /**
     * Material managers may open unreviewed content; nobody else may.
     */
    private function requireApproved(Request $request, Project $project): void
    {
        $staff = $request->user()->role === 'MaterialManager';

        abort_if(! $staff && ! $project->isApproved(), 404, 'Project not found.');
    }
}