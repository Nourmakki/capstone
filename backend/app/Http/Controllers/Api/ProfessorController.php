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
                'submission.quiz.questions',
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
                        'answers'     => $isQuiz ? $this->quizAnswers($submission) : null,
                        'status'      => $submission->status,
                        'score'       => $submission->score,
                        'ai_score'    => $submission->ai_score,
                        'ai_feedback' => $submission->ai_feedback,
                    ],
                ];
            });

        return response()->json(['requests' => $requests]);
    }
    /**
     * Pairs each quiz question with what the student answered so the
     * professor can grade without opening the quiz separately.
     *
     * The answer column is $hidden on the model, which only affects JSON
     * serialisation - reading it here is deliberate and this route is
     * restricted to staff.
     */
    private function quizAnswers(Submission $submission): array
    {
        $given = collect(json_decode($submission->content, true) ?? [])
            ->pluck('answer', 'question_id');

        return $submission->quiz?->questions
            ->map(fn ($q) => [
                'question_id'    => $q->id,
                'question'       => $q->question,
                'type'           => $q->type,
                'options'        => $q->options ?? [],
                'given'          => $given->get($q->id),
                'correct_answer' => $q->answer,
                'auto_gradable'  => in_array($q->type, ['multiple_choice', 'true_false'], true),
                'is_correct'     => in_array($q->type, ['multiple_choice', 'true_false'], true)
                    ? strcasecmp(trim((string) $given->get($q->id)), trim((string) $q->answer)) === 0
                    : null,
            ])->values()->all() ?? [];
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