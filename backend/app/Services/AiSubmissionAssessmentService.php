<?php

namespace App\Services;

use App\Models\NodeLearningWorkflow;
use App\Models\Submission;
use Illuminate\Validation\ValidationException;

/**
 * Grade a student's project submission with the AI system.
 *
 * The third of her three workflows, and the only one whose output belongs to a
 * student rather than to the course: the roadmap and learning workflows write
 * content for a manager to approve, this one writes a score on someone's work.
 *
 * So nothing here is destructive. The AI's verdict lands in ai_score /
 * ai_feedback beside the professor's own score, never on top of it -
 * Submission::finalScore() prefers the human's, falling back to the AI's when
 * no professor has looked yet.
 */
class AiSubmissionAssessmentService
{
    public function __construct(
        private AiWorkflowService $ai,
        private AiGradingService $grading
    ) {
    }

    /**
     * Send one submission for assessment and record what comes back.
     *
     * @throws ValidationException when the submission cannot be assessed
     */
    public function assess(Submission $submission): Submission
    {
        $fields = $this->fieldsFor($submission);

        $response = $this->ai->startProjectAssessment(
            $fields,
            $this->absolutePath($submission),
            $submission->file_path ? basename($submission->file_path) : null
        );

        if (! $response->successful()) {
            throw ValidationException::withMessages([
                'assessment' => $this->readableFailure($response->status(), $response->json()),
            ]);
        }

        $body = $response->json();

        // Her workflow reports its own failure with a 200 and status "failed",
        // so a successful HTTP call is not the same as a successful grading.
        if (($body['status'] ?? null) !== 'ready' || ! isset($body['data'])) {
            throw ValidationException::withMessages([
                'assessment' => 'The AI system could not grade this submission: '
                    . ($body['error'] ?? 'it returned no result.'),
            ]);
        }

        return $this->record($submission, $body);
    }

    /**
     * The multipart fields her endpoint expects.
     *
     * Everything here is refused rather than guessed. A wrong submission_type
     * is a 422 from her validator that would read as an AI failure, and a
     * missing thread id would be an empty string she rejects.
     */
    private function fieldsFor(Submission $submission): array
    {
        $hasFile   = filled($submission->file_path);
        $hasRepo   = filled($submission->github_url);

        if (! $hasFile && ! $hasRepo) {
            throw ValidationException::withMessages([
                'assessment' => 'This submission has neither a file nor a repository, so there is nothing to grade.',
            ]);
        }

        $fields = [
            'learning_thread_id' => $this->threadIdFor($submission),
            'submission_type'    => match (true) {
                $hasFile && $hasRepo => 'file and github',
                $hasRepo             => 'github',
                default              => 'file',
            },
        ];

        // Omitted, not null: her validator raises on a repository_url that is
        // merely present when the type is "file".
        if ($hasRepo) {
            $fields['repository_url'] = $submission->github_url;
        }

        if (filled($submission->content)) {
            $fields['student_note'] = $submission->content;
        }

        return $fields;
    }

    /**
     * The learning run this project came out of.
     *
     * Her assessment is graded against the material the learning workflow
     * generated for that module, so without a committed run there is nothing
     * to grade against and she has no criteria to apply.
     */
    private function threadIdFor(Submission $submission): string
    {
        $versionNodeId = $submission->project?->version_node_id;

        $workflow = $versionNodeId
            ? NodeLearningWorkflow::where('version_node_id', $versionNodeId)
                ->whereNotNull('thread_id')
                ->orderByDesc('id')
                ->first()
            : null;

        if (! $workflow) {
            throw ValidationException::withMessages([
                'assessment' => 'This module has no AI learning run to grade against. '
                    . 'Generate its learning material first, then assess the submission.',
            ]);
        }

        return $workflow->thread_id;
    }

    /** Where the stored file actually is, or null for a repository-only hand-in. */
    private function absolutePath(Submission $submission): ?string
    {
        if (! filled($submission->file_path)) {
            return null;
        }

        $path = \Storage::path($submission->file_path);

        if (! is_file($path)) {
            throw ValidationException::withMessages([
                'assessment' => 'The submitted file is no longer on disk, so it cannot be graded.',
            ]);
        }

        return $path;
    }

    /**
     * Write the verdict onto the submission.
     *
     * The mapping itself belongs to AiGradingService, which mirrors her
     * assessment_scoring.py - including STATUS_SCORE_VALUES, so a criterion
     * counts for the same here as it does there. Duplicating that logic would
     * mean two places to keep in step with her, and only one of them named
     * after the file it mirrors.
     *
     * It also recomputes the score when she sends an assessment without one,
     * which is the behaviour to keep: her numbers win where she provides them.
     *
     * total_score can reach 110 - base 0-100 plus up to 10 bonus - and is
     * rounded but deliberately not capped. Clamping would silently discard a
     * bonus she calculated.
     */
    private function record(Submission $submission, array $body): Submission
    {
        $data = $body['data'] ?? [];

        $submission = $this->grading->applyAssessment(
            $submission,
            $data['project_assessment_result'] ?? [],
            $data['assessment_score'] ?? null
        );

        // applyAssessment writes the grade, not the workflow state - the
        // status belongs to us, and only moves forward: a professor who has
        // already marked this keeps human_reviewed, the later state.
        if ($submission->status !== 'human_reviewed') {
            $submission->update(['status' => 'ai_reviewed']);
        }

        return $submission->fresh();
    }

    /** Her 422s carry a detail array; a dead service carries nothing useful. */
    private function readableFailure(int $status, mixed $body): string
    {
        if ($status === 422) {
            $detail = $body['detail'] ?? null;

            $message = is_array($detail)
                ? implode('; ', array_map(
                    fn ($e) => is_array($e) ? ($e['msg'] ?? json_encode($e)) : (string) $e,
                    $detail
                ))
                : (string) $detail;

            return 'The AI system rejected this submission: ' . $message;
        }

        return "The AI system could not be reached (HTTP {$status}). "
            . 'Check that it is running, then try again.';
    }
}
