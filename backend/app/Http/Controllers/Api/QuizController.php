<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\SubmitQuizRequest;
use App\Models\Quiz;
use App\Models\Student;
use App\Models\Submission;
use Illuminate\Http\Request;

class QuizController extends Controller
{
    /**
     * Pass mark, as a percentage.
     */
    public const PASS_MARK = 70;

    /**
     * Question types this platform can grade without a human.
     */
    private const AUTO_GRADED = ['multiple_choice', 'true_false'];

    /**
     * The quiz as a student should see it - no answers.
     */
    public function show(Request $request, Quiz $quiz)
    {
        // An unapproved quiz must not be reachable by guessing its id.
        $this->requireApproved($request, $quiz);

        $quiz->load('questions');

        return response()->json([
            'id'         => $quiz->id,
            'title'      => $quiz->title,
            'version_node_id' => $quiz->version_node_id,
            'pass_mark'  => self::PASS_MARK,
            'questions'  => $quiz->questions->map(fn ($q) => [
                'id'       => $q->id,
                'question' => $q->question,
                'type'     => $q->type,
                // Short answers have no options to choose from.
                'options'  => $q->type === 'short_answer' ? [] : ($q->options ?? []),
            ]),
            'last_attempt' => $this->latestSubmission($request, $quiz),
        ]);
    }

    /**
     * Grade a submission. Multiple-choice and true/false are scored
     * immediately; short answers are stored for review.
     */
    public function submit(SubmitQuizRequest $request, Quiz $quiz)
    {
        $this->requireApproved($request, $quiz);

        $student = $this->studentFor($request);

        $quiz->load('questions');

        // question_id => submitted answer
        $submitted = collect($request->validated()['answers'])
            ->pluck('answer', 'question_id');

        $autoTotal   = 0;
        $autoCorrect = 0;
        $needsReview = false;
        $breakdown   = [];

        foreach ($quiz->questions as $question) {
            $given = $submitted->get($question->id);

            if (! in_array($question->type, self::AUTO_GRADED, true)) {
                $needsReview = true;

                $breakdown[] = [
                    'question_id' => $question->id,
                    'type'        => $question->type,
                    'given'       => $given,
                    'correct'     => null,
                    'awaiting_review' => true,
                ];

                continue;
            }

            $autoTotal++;

            // Case-insensitive so "true" and "True" both count.
            $isCorrect = $given !== null
                && strcasecmp(trim($given), trim($question->answer)) === 0;

            if ($isCorrect) {
                $autoCorrect++;
            }

            $breakdown[] = [
                'question_id'    => $question->id,
                'type'           => $question->type,
                'given'          => $given,
                'correct'        => $isCorrect,
                // Revealing the answer is fine now that it has been graded.
                'correct_answer' => $question->answer,
                'awaiting_review' => false,
            ];
        }

        $score = $autoTotal > 0
            ? (int) round(($autoCorrect / $autoTotal) * 100)
            : 0;

        $submission = Submission::create([
            'quiz_id'    => $quiz->id,
            'student_id' => $student->id,
            'content'    => json_encode($request->validated()['answers']),
            'status'     => 'submitted',
            'score'      => $score,
            'comments'   => $needsReview
                ? 'Contains short answers awaiting review.'
                : null,
        ]);

        return response()->json([
            'submission_id' => $submission->id,
            'score'         => $score,
            'passed'        => $score >= self::PASS_MARK,
            'pass_mark'     => self::PASS_MARK,
            'auto_graded'   => ['correct' => $autoCorrect, 'total' => $autoTotal],
            'needs_review'  => $needsReview,
            // Null until the AI service explains the attempt; the client
            // renders the explanation panel only when this is populated.
            'ai_feedback'   => $submission->ai_feedback,
            'breakdown'     => $breakdown,
        ], 201);
    }

    /**
     * Every attempt this student has made at this quiz.
     */
    public function attempts(Request $request, Quiz $quiz)
    {
        $student = $this->studentFor($request);

        $attempts = Submission::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($s) => [
                'id'         => $s->id,
                'score'      => $s->score,
                'passed'     => $s->score >= self::PASS_MARK,
                'status'     => $s->status,
                'created_at' => $s->created_at,
            ]);

        return response()->json(['attempts' => $attempts]);
    }

    private function latestSubmission(Request $request, Quiz $quiz): ?array
    {
        $student = Student::where('user_id', $request->user()->id)->first();

        if (! $student) {
            return null;
        }

        $latest = Submission::where('quiz_id', $quiz->id)
            ->where('student_id', $student->id)
            ->latest()
            ->first();

        return $latest ? [
            'id'         => $latest->id,
            'score'      => $latest->score,
            'passed'     => $latest->score >= self::PASS_MARK,
            'created_at' => $latest->created_at,
        ] : null;
    }

    private function studentFor(Request $request): Student
    {
        return Student::where('user_id', $request->user()->id)->firstOrFail();
    }

    /**
     * Staff may open unreviewed content; students may not.
     */
    private function requireApproved(Request $request, Quiz $quiz): void
    {
        $staff = in_array($request->user()->role, ['Admin', 'MaterialManager'], true);

        abort_if(! $staff && ! $quiz->isApproved(), 404, 'Quiz not found.');
    }
}