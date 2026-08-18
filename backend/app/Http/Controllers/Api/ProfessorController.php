<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\ReviewSubmissionRequest;
use App\Models\HumanReviewRequest;
use App\Models\Professor;
use App\Models\Submission;
use Illuminate\Http\Request;

class ProfessorController extends Controller
{
    /**
     * Pending requests: unclaimed ones, plus anything this professor claimed.
     */
    public function reviewRequests(Request $request)
    {
        $professor = $this->professorFor($request);

        $requests = HumanReviewRequest::with([
                'submission.student.user',
                'submission.project',
                'submission.quiz',
            ])
            ->where('status', 'pending')
            ->where(function ($q) use ($professor) {
                $q->whereNull('professor_id')
                  ->orWhere('professor_id', $professor->id);
            })
            ->orderBy('created_at')
            ->get()
            ->map(function ($r) use ($professor) {
                $submission = $r->submission;

                // A request is either for a project or for a quiz.
                $isQuiz = $submission->quiz_id !== null;

                return [
                    'id'           => $r->id,
                    'claimed'      => $r->professor_id !== null,
                    'mine'         => $r->professor_id === $professor->id,
                    'requested_at' => $r->created_at,
                    'student'      => $submission->student->user->name ?? 'Unknown',
                    'kind'         => $isQuiz ? 'quiz' : 'project',
                    'title'        => $isQuiz
                        ? ($submission->quiz->title ?? 'Unknown quiz')
                        : ($submission->project->title ?? 'Unknown project'),
                    'submission'   => [
                        'id'          => $submission->id,
                        'github_url'  => $submission->github_url,
                        'has_file'    => (bool) $submission->file_path,
                        'notes'       => $isQuiz ? null : $submission->content,
                        'answers'     => $isQuiz ? json_decode($submission->content, true) : null,
                        'status'      => $submission->status,
                        'score'       => $submission->score,
                        'ai_score'    => $submission->ai_score,
                        'ai_feedback' => $submission->ai_feedback,
                    ],
                ];
            });

        return response()->json(['requests' => $requests]);
    }
    public function claim(Request $request, HumanReviewRequest $reviewRequest)
    {
        $professor = $this->professorFor($request);

        if ($reviewRequest->professor_id !== null) {
            return response()->json([
                'message' => 'Another professor has already claimed this request.',
            ], 409);
        }

        $reviewRequest->update(['professor_id' => $professor->id]);

        return response()->json(['message' => 'Review claimed.']);
    }

    public function review(ReviewSubmissionRequest $request, HumanReviewRequest $reviewRequest)
    {
        $professor = $this->professorFor($request);

        if ($reviewRequest->professor_id !== $professor->id) {
            return response()->json([
                'message' => 'Claim this request before reviewing it.',
            ], 403);
        }

        if ($reviewRequest->status !== 'pending') {
            return response()->json([
                'message' => 'This request has already been handled.',
            ], 409);
        }

        $data = $request->validated();

        $reviewRequest->update([
            'status'   => 'reviewed',
            'feedback' => $data['feedback'],
        ]);

        Submission::where('id', $reviewRequest->submission_id)->update([
            'score'    => $data['score'],
            'comments' => $data['feedback'],
            'status'   => 'human_reviewed',
        ]);

        return response()->json(['message' => 'Review submitted.']);
    }

    public function stats(Request $request)
    {
        $professor = $this->professorFor($request);

        return response()->json([
            'pending_unclaimed' => HumanReviewRequest::whereNull('professor_id')
                ->where('status', 'pending')->count(),
            'claimed_by_me'     => HumanReviewRequest::where('professor_id', $professor->id)
                ->where('status', 'pending')->count(),
            'reviewed_by_me'    => HumanReviewRequest::where('professor_id', $professor->id)
                ->where('status', 'reviewed')->count(),
        ]);
    }

    private function professorFor(Request $request): Professor
    {
        return Professor::where('user_id', $request->user()->id)->firstOrFail();
    }
}