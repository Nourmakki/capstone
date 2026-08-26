<?php

namespace Tests\Feature;

use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Models\Submission;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class QuizScoringTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private Quiz $quiz;
    private array $questions = [];

    /**
     * A quiz on the first module of a roadmap: two multiple choice and one
     * true/false, all auto-gradable.
     */
    private function makeQuiz(string $reviewStatus = 'approved'): Quiz
    {
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $this->quiz = Quiz::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Basics Quiz',
            'score'           => 0,
            'review_status'   => $reviewStatus,
        ]);

        $this->questions = [
            QuizQuestion::create([
                'quiz_id'  => $this->quiz->id,
                'question' => 'What is React for?',
                'type'     => 'multiple_choice',
                'options'  => ['Databases', 'User interfaces', 'Networking'],
                'answer'   => 'User interfaces',
            ]),
            QuizQuestion::create([
                'quiz_id'  => $this->quiz->id,
                'question' => 'Which hook stores state?',
                'type'     => 'multiple_choice',
                'options'  => ['useState', 'useEffect', 'useRef'],
                'answer'   => 'useState',
            ]),
            QuizQuestion::create([
                'quiz_id'  => $this->quiz->id,
                'question' => 'JSX compiles to JavaScript.',
                'type'     => 'true_false',
                'options'  => ['True', 'False'],
                'answer'   => 'True',
            ]),
        ];

        return $this->quiz;
    }

    private function answers(array $given): array
    {
        return ['answers' => collect($this->questions)
            ->map(fn ($q, $i) => ['question_id' => $q->id, 'answer' => $given[$i] ?? null])
            ->values()
            ->all()];
    }

    // -----------------------------------------------------------------
    // The answer must never reach the student
    // -----------------------------------------------------------------

    public function test_showing_a_quiz_never_reveals_the_answers(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        $response = $this->actingAs($user, 'sanctum')->getJson("/api/quizzes/{$this->quiz->id}");

        $response->assertOk()->assertJsonCount(3, 'questions');

        // This leaked once through a payload that returned models wholesale.
        $body = $response->getContent();

        $this->assertStringNotContainsString('"answer"', $body);
        $this->assertStringNotContainsString('ai_metadata', $body);

        foreach ($response->json('questions') as $question) {
            $this->assertArrayNotHasKey('answer', $question);
        }
    }

    public function test_quiz_metadata_stays_server_side_even_when_populated(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        // The quiz agent puts correct_answer_explanation here, which gives
        // the answer away as surely as the answer column does.
        $this->questions[0]->update([
            'ai_metadata' => ['correct_answer_explanation' => 'It is User interfaces because React renders UI.'],
        ]);

        $body = $this->actingAs($user, 'sanctum')
            ->getJson("/api/quizzes/{$this->quiz->id}")
            ->getContent();

        $this->assertStringNotContainsString('User interfaces because', $body);
        $this->assertStringNotContainsString('ai_metadata', $body);
    }

    // -----------------------------------------------------------------
    // Scoring
    // -----------------------------------------------------------------

    public function test_all_correct_scores_one_hundred_and_passes(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'User interfaces', 'useState', 'True',
            ]))
            ->assertCreated()
            ->assertJsonPath('score', 100)
            ->assertJsonPath('passed', true)
            ->assertJsonPath('needs_review', false)
            ->assertJsonPath('auto_graded.correct', 3)
            ->assertJsonPath('auto_graded.total', 3);
    }

    public function test_all_wrong_scores_zero_and_fails(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'Databases', 'useEffect', 'False',
            ]))
            ->assertCreated()
            ->assertJsonPath('score', 0)
            ->assertJsonPath('passed', false);
    }

    public function test_two_of_three_rounds_to_sixty_seven_and_still_fails(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        // 2/3 = 66.67, rounded to 67 - below the 70 pass mark.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'User interfaces', 'useState', 'False',
            ]))
            ->assertCreated()
            ->assertJsonPath('score', 67)
            ->assertJsonPath('passed', false)
            ->assertJsonPath('pass_mark', 70);
    }

    public function test_answers_are_graded_case_insensitively(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        // "true" and "True" must both count, or the pass depends on the
        // casing the client happened to send.
        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'user interfaces', 'USESTATE', '  true  ',
            ]))
            ->assertCreated()
            ->assertJsonPath('score', 100);
    }

    public function test_an_unanswered_question_is_wrong_rather_than_an_error(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'User interfaces', null, null,
            ]))
            ->assertCreated()
            ->assertJsonPath('score', 33);
    }

    // -----------------------------------------------------------------
    // Short answers
    // -----------------------------------------------------------------

    public function test_a_short_answer_is_flagged_for_review_and_excluded_from_the_score(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        $this->questions[] = QuizQuestion::create([
            'quiz_id'  => $this->quiz->id,
            'question' => 'Explain the virtual DOM.',
            'type'     => 'short_answer',
            'options'  => null,
            'answer'   => 'A lightweight copy of the DOM.',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'User interfaces', 'useState', 'True', 'Some prose.',
            ]));

        $response->assertCreated()
            ->assertJsonPath('needs_review', true)
            // Only the three auto-gradable ones count towards the score.
            ->assertJsonPath('auto_graded.total', 3)
            ->assertJsonPath('score', 100);

        $shortAnswer = collect($response->json('breakdown'))->firstWhere('type', 'short_answer');

        $this->assertTrue($shortAnswer['awaiting_review']);
        $this->assertNull($shortAnswer['correct']);

        // A pending short answer must not reveal the expected answer.
        $this->assertArrayNotHasKey('correct_answer', $shortAnswer);
    }

    public function test_a_short_answer_only_quiz_scores_zero_rather_than_dividing_by_zero(): void
    {
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $quiz = Quiz::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Essay Quiz',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        $question = QuizQuestion::create([
            'quiz_id'  => $quiz->id,
            'question' => 'Explain hooks.',
            'type'     => 'short_answer',
            'options'  => null,
            'answer'   => 'Functions that let you use state.',
        ]);

        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$quiz->id}/submit", [
                'answers' => [['question_id' => $question->id, 'answer' => 'Some prose.']],
            ])
            ->assertCreated()
            ->assertJsonPath('score', 0)
            ->assertJsonPath('auto_graded.total', 0)
            ->assertJsonPath('needs_review', true);
    }

    // -----------------------------------------------------------------
    // Storage and access
    // -----------------------------------------------------------------

    public function test_the_attempt_is_stored_against_the_student(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $this->actingAs($user, 'sanctum')
            ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers([
                'User interfaces', 'useState', 'True',
            ]))->assertCreated();

        $submission = Submission::where('quiz_id', $this->quiz->id)->first();

        $this->assertSame($student->id, $submission->student_id);
        $this->assertSame(100, $submission->score);
        $this->assertSame('submitted', $submission->status);
    }

    public function test_every_attempt_is_kept_so_the_best_can_be_used(): void
    {
        $this->makeQuiz();
        $user = $this->makeStudent();

        foreach ([['User interfaces', 'useState', 'True'], ['Databases', 'useEffect', 'False']] as $attempt) {
            $this->actingAs($user, 'sanctum')
                ->postJson("/api/quizzes/{$this->quiz->id}/submit", $this->answers($attempt))
                ->assertCreated();
        }

        // Retaking must not overwrite a pass - completionBlockers reads max().
        $this->assertSame(2, Submission::where('quiz_id', $this->quiz->id)->count());

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/quizzes/{$this->quiz->id}/attempts")
            ->assertOk();
    }

    public function test_an_unapproved_quiz_is_not_reachable_by_guessing_its_id(): void
    {
        $this->makeQuiz('pending');
        $user = $this->makeStudent();

        $this->actingAs($user, 'sanctum')
            ->getJson("/api/quizzes/{$this->quiz->id}")
            ->assertNotFound();
    }
}