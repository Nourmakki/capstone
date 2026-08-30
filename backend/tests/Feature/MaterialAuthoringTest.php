<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\User;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The rules a material manager's quiz has to satisfy.
 *
 * Multiple choice carries exactly four options and true/false exactly two, so
 * one question cannot quietly become another type by losing its options.
 */
class MaterialAuthoringTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private VersionNode $module;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();

        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $this->module  = $nodes[0];
        $this->manager = $this->makeManager();
    }

    /** @param array<int,string> $options */
    private function question(array $options, string $answer, string $type = 'multiple_choice'): array
    {
        return [
            'title'     => 'Basics Quiz',
            'questions' => [[
                'question' => 'What is React primarily used for?',
                'type'     => $type,
                'options'  => $options,
                'answer'   => $answer,
            ]],
        ];
    }

    private function createQuiz(array $payload)
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/version-nodes/{$this->module->id}/quizzes", $payload);
    }

    public function test_four_options_are_accepted(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'User interfaces'
        ))->assertStatus(201);

        $this->assertCount(4, QuizQuestion::sole()->options);
    }

    public function test_fewer_than_four_options_are_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking'],
            'User interfaces'
        ))->assertStatus(422);

        $this->assertSame(0, Quiz::count());
    }

    public function test_more_than_four_options_are_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems', 'Compilers'],
            'User interfaces'
        ))->assertStatus(422);

        $this->assertSame(0, Quiz::count());
    }

    /**
     * The editor sends four slots whether or not they were filled, so a
     * half-finished question arrives as four options with blanks in it. Blanks
     * are stripped before counting, which is what makes this the same failure
     * as sending three.
     */
    public function test_four_slots_with_a_blank_in_them_are_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', '', '   '],
            'User interfaces'
        ))->assertStatus(422);

        $this->assertSame(0, Quiz::count());
    }

    /**
     * The answer is matched on text, so a duplicated option is simultaneously
     * right and wrong. Nour's generator forbids it too (the unique-answers
     * validator in learning_workflow/schema.py), so hand-authored quizzes are
     * held to the same standard as generated ones.
     */
    public function test_two_identical_options_are_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Databases'],
            'User interfaces'
        ))->assertStatus(422);

        $this->assertSame(0, Quiz::count());
    }

    /** Same text, different case and spacing, is still the same option. */
    public function test_duplicates_are_matched_ignoring_case_and_spacing(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', '  DATABASES '],
            'User interfaces'
        ))->assertStatus(422);

        $this->assertSame(0, Quiz::count());
    }

    public function test_true_false_still_takes_exactly_two(): void
    {
        $this->createQuiz($this->question(['True', 'False'], 'True', 'true_false'))
            ->assertStatus(201);

        $this->assertCount(2, QuizQuestion::sole()->options);
    }

    public function test_short_answer_needs_no_options(): void
    {
        $this->createQuiz($this->question([], 'A JavaScript library', 'short_answer'))
            ->assertStatus(201);

        $this->assertNull(QuizQuestion::sole()->options);
    }

    /**
     * The option count is checked before the answer is matched against the
     * options, so a wrong count is reported as a wrong count.
     */
    public function test_an_answer_outside_the_options_is_still_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'Something else'
        ))->assertStatus(422);

        $this->assertSame(0, Quiz::count());
    }

    public function test_the_rule_applies_to_edits_as_well_as_creation(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'User interfaces'
        ))->assertStatus(201);

        $quiz = Quiz::sole();

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/quizzes/{$quiz->id}", $this->question(
                ['Databases', 'User interfaces'],
                'User interfaces'
            ))
            ->assertStatus(422);

        // The original four are untouched: the whole update is one transaction.
        $this->assertCount(4, QuizQuestion::sole()->options);
    }

    // -----------------------------------------------------------------
    // What the review queue is told about an edit
    // -----------------------------------------------------------------

    /** A quiz that has been approved, so an edit sends it back. */
    private function approvedQuiz(): Quiz
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'User interfaces'
        ))->assertStatus(201);

        $quiz = Quiz::sole();
        $quiz->update(['review_status' => 'approved']);

        return $quiz->fresh();
    }

    /** @param array<int,string> $options */
    private function putQuiz(Quiz $quiz, string $title, array $questions)
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/quizzes/{$quiz->id}", compact('title', 'questions'));
    }

    private function fourOptions(string $question, string $answer, ?int $id = null): array
    {
        return [
            'id'       => $id,
            'question' => $question,
            'type'     => 'multiple_choice',
            'options'  => ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'answer'   => $answer,
        ];
    }

    public function test_editing_a_question_records_what_changed(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = QuizQuestion::sole();

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions('What is React actually for?', 'User interfaces', $question->id),
        ])->assertStatus(200);

        $quiz->refresh();

        $this->assertSame('pending', $quiz->review_status);
        $this->assertSame('1 question edited.', $quiz->change_note);
    }

    public function test_the_note_names_every_kind_of_change_at_once(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = QuizQuestion::sole();

        // Title reworded, the existing question reworded, and a second added.
        $this->putQuiz($quiz, 'React Basics Quiz', [
            $this->fourOptions('What is React actually for?', 'User interfaces', $question->id),
            $this->fourOptions('Which one builds interfaces?', 'User interfaces'),
        ])->assertStatus(200);

        $this->assertSame(
            'Title changed, 1 question edited, 1 question added.',
            $quiz->fresh()->change_note
        );
    }

    public function test_removing_a_question_is_reported(): void
    {
        $quiz = $this->approvedQuiz();

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions('A brand new question', 'Networking'),
        ])->assertStatus(200);

        // The original was dropped and a different one put in its place.
        $this->assertSame('1 question added, 1 question removed.', $quiz->fresh()->change_note);
    }

    /**
     * Saving without touching anything must not fill the review queue. The
     * editor sends the whole quiz on every save, so this is the common case
     * of opening something, looking at it, and pressing Save.
     */
    public function test_an_edit_that_changes_nothing_leaves_the_quiz_approved(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = QuizQuestion::sole();

        $this->putQuiz($quiz, $quiz->title, [
            $this->fourOptions($question->question, $question->answer, $question->id),
        ])->assertStatus(200);

        $quiz->refresh();

        $this->assertSame('approved', $quiz->review_status);
        $this->assertNull($quiz->change_note);
    }

    public function test_a_project_edit_names_the_fields_it_touched(): void
    {
        $response = $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/version-nodes/{$this->module->id}/projects", [
                'title'       => 'Build a To-Do App',
                'description' => 'Components, state, events.',
                'difficulty'  => 'beginner',
            ]);

        $project = Project::findOrFail($response->json('project_id'));
        $project->update(['review_status' => 'approved']);

        $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/projects/{$project->id}", [
                'title'       => 'Build a Better To-Do App',
                'description' => 'Components, state, events.',
                'difficulty'  => 'intermediate',
            ])
            ->assertStatus(200);

        $project->refresh();

        $this->assertSame('pending', $project->review_status);
        $this->assertSame('Title and difficulty changed.', $project->change_note);
    }

    /** The note describes a pending edit; a decision makes it history. */
    public function test_reviewing_the_item_clears_the_note(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = QuizQuestion::sole();

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions('Reworded again', 'Networking', $question->id),
        ])->assertStatus(200);

        $this->assertNotNull($quiz->fresh()->change_note);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson("/api/material/quizzes/{$quiz->id}/approve")
            ->assertStatus(200);

        $quiz->refresh();

        $this->assertSame('approved', $quiz->review_status);
        $this->assertNull($quiz->change_note);
    }

    public function test_the_queue_carries_the_note(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = QuizQuestion::sole();

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions('Reworded for the queue', 'Networking', $question->id),
        ])->assertStatus(200);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/material/pending')
            ->assertStatus(200)
            ->assertJsonPath('quizzes.0.change_note', '1 question edited.');
    }
}
