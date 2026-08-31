<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Submission;
use App\Services\AiSubmissionAssessmentService;
use Illuminate\Http\Request;

/**
 * Ask the AI system to grade a submission.
 *
 * Triggered rather than automatic. Assessment clones repositories and reads
 * notebooks, so it takes minutes and spends API quota - running it inside
 * ProjectController::submit() would leave the student watching a spinner on
 * the request that hands in their work, and would grade every draft.
 *
 * A professor asking for it when they sit down to mark is both cheaper and
 * closer to how the score is used: Submission::finalScore() treats the AI as
 * the fallback for work a human has not reached yet.
 */
class SubmissionAssessmentController extends Controller
{
    public function __construct(private AiSubmissionAssessmentService $assessment)
    {
    }

    public function assess(Request $request, Submission $submission)
    {
        // Quiz submissions are scored by our own grader; only project work
        // has anything for her workflow to read.
        if ($submission->project_id === null) {
            return response()->json([
                'message' => 'Only project submissions can be assessed by the AI.',
            ], 422);
        }

        $submission = $this->assessment->assess($submission->load('project'));

        return response()->json([
            'message'    => 'Assessment complete.',
            'assessment' => $this->payload($submission),
        ]);
    }

    /** What the AI said last time, without running it again. */
    public function show(Request $request, Submission $submission)
    {
        if ($submission->ai_reviewed_at === null) {
            return response()->json([
                'message' => 'This submission has not been assessed yet.',
            ], 404);
        }

        return response()->json(['assessment' => $this->payload($submission)]);
    }

    private function payload(Submission $submission): array
    {
        $metadata = json_decode($submission->ai_metadata ?? 'null', true);
        $data     = $metadata['data'] ?? [];
        $result   = $data['project_assessment_result'] ?? [];

        return [
            'submission_id'  => $submission->id,
            'ai_score'       => $submission->ai_score,
            'ai_feedback'    => $submission->ai_feedback,
            'ai_reviewed_at' => $submission->ai_reviewed_at,
            'status'         => $submission->status,
            // The professor's own score, so a reviewer can see both at once.
            'score'          => $submission->score,
            'final_score'    => $submission->finalScore(),

            // The detail behind the number. Absent on an older assessment that
            // predates ai_metadata, hence the defaults.
            'breakdown'      => [
                'base_score'  => $data['assessment_score']['base_score'] ?? null,
                'bonus_score' => $data['assessment_score']['bonus_score'] ?? null,
                'total_score' => $data['assessment_score']['total_score'] ?? null,
                'criteria'    => array_map(fn ($c) => [
                    'criterion' => $c['criterion'] ?? '',
                    'status'    => $c['status'] ?? '',
                    'evidence'  => $c['evidence'] ?? '',
                    'feedback'  => $c['feedback'] ?? '',
                ], $result['criterion_assessments'] ?? []),
                'strengths'    => $result['strengths'] ?? [],
                'improvements' => $result['improvements'] ?? [],
                'violations'   => $result['constraint_violations'] ?? [],
            ],
        ];
    }
}
