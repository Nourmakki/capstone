<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Roadmap;
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

    /**
     * Put a quiz payload through the validator a manager can actually reach.
     *
     * Managers no longer create material - the AI does, and they curate it -
     * so these rules are exercised on the edit path. The quiz being edited is
     * seeded directly, standing in for one the learning workflow committed.
     */
    private function createQuiz(array $payload)
    {
        $quiz = Quiz::create([
            'version_node_id' => $this->module->id,
            'title'           => 'Seeded by the AI',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        QuizQuestion::create([
            'quiz_id'  => $quiz->id,
            'question' => 'Placeholder committed by the learning workflow.',
            'type'     => 'multiple_choice',
            'options'  => ['A', 'B', 'C', 'D'],
            'answer'   => 'A',
        ]);

        return $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/quizzes/{$quiz->id}", $payload);
    }

    public function test_four_options_are_accepted(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'User interfaces'
        ))->assertOk();

        $this->assertCount(4, QuizQuestion::sole()->options);
    }

    public function test_fewer_than_four_options_are_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking'],
            'User interfaces'
        ))->assertStatus(422);

        // Refused, so the question the AI committed is untouched.
        $this->assertSame('Placeholder committed by the learning workflow.', QuizQuestion::sole()->question);
    }

    public function test_more_than_four_options_are_refused(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems', 'Compilers'],
            'User interfaces'
        ))->assertStatus(422);

        // Refused, so the question the AI committed is untouched.
        $this->assertSame('Placeholder committed by the learning workflow.', QuizQuestion::sole()->question);
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

        // Refused, so the question the AI committed is untouched.
        $this->assertSame('Placeholder committed by the learning workflow.', QuizQuestion::sole()->question);
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

        // Refused, so the question the AI committed is untouched.
        $this->assertSame('Placeholder committed by the learning workflow.', QuizQuestion::sole()->question);
    }

    /** Same text, different case and spacing, is still the same option. */
    public function test_duplicates_are_matched_ignoring_case_and_spacing(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', '  DATABASES '],
            'User interfaces'
        ))->assertStatus(422);

        // Refused, so the question the AI committed is untouched.
        $this->assertSame('Placeholder committed by the learning workflow.', QuizQuestion::sole()->question);
    }

    public function test_true_false_still_takes_exactly_two(): void
    {
        $this->createQuiz($this->question(['True', 'False'], 'True', 'true_false'))
            ->assertOk();

        $this->assertCount(2, QuizQuestion::sole()->options);
    }

    public function test_short_answer_needs_no_options(): void
    {
        $this->createQuiz($this->question([], 'A JavaScript library', 'short_answer'))
            ->assertOk();

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

        // Refused, so the question the AI committed is untouched.
        $this->assertSame('Placeholder committed by the learning workflow.', QuizQuestion::sole()->question);
    }

    public function test_the_rule_applies_to_edits_as_well_as_creation(): void
    {
        $this->createQuiz($this->question(
            ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'User interfaces'
        ))->assertOk();

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
        ))->assertOk();

        $quiz = Quiz::sole();
        $quiz->update(['review_status' => 'approved', 'change_note' => null]);

        return $quiz->fresh();
    }

    /** @param array<int,string> $options */
    private function putQuiz(Quiz $quiz, string $title, array $questions)
    {
        return $this->actingAs($this->manager, 'sanctum')
            ->putJson("/api/quizzes/{$quiz->id}", compact('title', 'questions'));
    }

    private function fourOptions(
        string $question,
        string $answer,
        ?int $id = null,
        ?string $explanation = null
    ): array {
        return array_filter([
            'id'          => $id,
            'question'    => $question,
            'type'        => 'multiple_choice',
            'options'     => ['Databases', 'User interfaces', 'Networking', 'Operating systems'],
            'answer'      => $answer,
            'explanation' => $explanation,
        ], fn ($value) => $value !== null);
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
        $project = Project::create([
            'version_node_id' => $this->module->id,
            'title'           => 'Build a To-Do App',
            'description'     => 'Components, state, events.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

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

    // -----------------------------------------------------------------
    // The AI's explanation must not outlive the question it explained
    // -----------------------------------------------------------------

    /** A question as the AI commit service leaves it, explanation and all. */
    private function aiQuestion(Quiz $quiz): QuizQuestion
    {
        $question = QuizQuestion::sole();

        $question->update(['ai_metadata' => [
            'question_type'              => 'conceptual',
            'question'                   => $question->question,
            'correct_answer_explanation' => 'React is a library for building user interfaces.',
        ]]);

        return $question->fresh();
    }

    /**
     * The rule matched from validate_edited_quiz() in
     * learning_workflow/graph/workflow.py: content and explanation move
     * together or the edit is refused.
     */
    public function test_rewording_a_question_without_updating_the_explanation_is_refused(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = $this->aiQuestion($quiz);

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions('What does React actually do?', 'User interfaces', $question->id),
        ])->assertStatus(422);

        // The whole update is one transaction, so nothing moved.
        $this->assertSame('What is React primarily used for?', $question->fresh()->question);
    }

    /** Sending the old explanation back unchanged is the same failure. */
    public function test_resending_the_same_explanation_is_refused(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = $this->aiQuestion($quiz);

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions(
                'What does React actually do?',
                'User interfaces',
                $question->id,
                'React is a library for building user interfaces.'
            ),
        ])->assertStatus(422);
    }

    public function test_updating_both_together_is_accepted(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = $this->aiQuestion($quiz);

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions(
                'What does React actually do?',
                'User interfaces',
                $question->id,
                'It renders and updates the user interface as state changes.'
            ),
        ])->assertStatus(200);

        $metadata = $question->fresh()->ai_metadata;

        $this->assertSame(
            'It renders and updates the user interface as state changes.',
            $metadata['correct_answer_explanation']
        );

        // The rest is provenance and survives untouched.
        $this->assertSame('conceptual', $metadata['question_type']);
    }

    /** Changing only which option is correct counts as content changing. */
    public function test_changing_only_the_answer_needs_a_new_explanation_too(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = $this->aiQuestion($quiz);

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions($question->question, 'Networking', $question->id),
        ])->assertStatus(422);
    }

    /**
     * A hand-written question has no explanation, so there is nothing to keep
     * in step and the rule must not block ordinary editing.
     */
    public function test_a_question_with_no_explanation_edits_freely(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = QuizQuestion::sole();

        $this->assertNull($question->ai_metadata);

        $this->putQuiz($quiz, 'Basics Quiz', [
            $this->fourOptions('Reworded with no explanation anywhere', 'Networking', $question->id),
        ])->assertStatus(200);
    }

    /**
     * The explanation is about the question, not the quiz. Renaming the quiz
     * leaves every question exactly as the AI wrote it, so the explanations
     * are still true and must survive.
     */
    public function test_renaming_the_quiz_leaves_the_explanation_alone(): void
    {
        $quiz     = $this->approvedQuiz();
        $question = $this->aiQuestion($quiz);

        $this->putQuiz($quiz, 'A Better Quiz Title', [
            $this->fourOptions($question->question, $question->answer, $question->id),
        ])->assertStatus(200);

        $this->assertSame(
            'React is a library for building user interfaces.',
            $question->fresh()->ai_metadata['correct_answer_explanation']
        );
    }

    /**
     * The editor loads the explanation from here, so it has to come back or a
     * manager cannot satisfy the rule without retyping it from memory.
     */
    public function test_the_material_endpoint_serves_the_explanation(): void
    {
        $quiz = $this->approvedQuiz();
        $this->aiQuestion($quiz);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson("/api/version-nodes/{$this->module->id}/material")
            ->assertJsonPath(
                'quizzes.0.questions.0.explanation',
                'React is a library for building user interfaces.'
            );
    }

    /** A new question can carry one, so hand-authored quizzes can have them. */
    public function test_a_new_question_can_be_given_an_explanation(): void
    {
        $this->createQuiz([
            'title'     => 'Basics Quiz',
            'questions' => [
                $this->fourOptions(
                    'What is React primarily used for?',
                    'User interfaces',
                    null,
                    'Because it builds and updates user interfaces.'
                ),
            ],
        ])->assertOk();

        $this->assertSame(
            'Because it builds and updates user interfaces.',
            QuizQuestion::sole()->ai_metadata['correct_answer_explanation']
        );
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

    /**
     * Every pending item says where it belongs, so the queue can file it.
     *
     * Without this the queue is six flat lists by type, and a committed
     * subject arrives as seventeen rows with nothing saying they are one
     * tree. The subject is what the folders are keyed on.
     */
    public function test_the_queue_says_where_each_item_belongs(): void
    {
        $this->approvedQuiz();

        $this->putQuiz(Quiz::sole(), 'Basics Quiz', [
            $this->fourOptions('Reworded', 'Networking', QuizQuestion::sole()->id),
        ])->assertStatus(200);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/material/pending')
            ->assertStatus(200)
            // Walked up from the module's roadmap to the subject above it.
            ->assertJsonPath('quizzes.0.subject', 'Test Subject')
            ->assertJsonPath('quizzes.0.roadmap', 'Test Roadmap')
            ->assertJsonPath('quizzes.0.module', 'Basics');
    }

    /**
     * The folder button approves a batch in one request.
     *
     * The roadmap has to be approved before the version that belongs to it,
     * whatever order they arrive in - so they are sent deliberately backwards
     * here, which is also the order the page would send them in if somebody
     * reordered the list.
     */
    public function test_approving_many_approves_them_in_dependency_order(): void
    {
        $roadmap = Roadmap::where('title', 'Test Roadmap')->sole();
        $roadmap->update(['review_status' => 'pending']);

        $version = $roadmap->versions()->sole();
        $version->update(['review_status' => 'pending']);

        // Coming-soon, as a freshly built module is: it has no material yet,
        // and that mark is what exempts it from the publish gate.
        $this->module->update(['coming_soon' => true]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/material/approve-many', [
                'items' => [
                    ['type' => 'roadmap-versions', 'id' => $version->id],
                    ['type' => 'roadmaps', 'id' => $roadmap->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('approved', 2)
            ->assertJsonPath('failed', []);

        $this->assertSame('approved', $roadmap->fresh()->review_status);
        $this->assertSame('approved', $version->fresh()->review_status);
        $this->assertSame($this->manager->id, $roadmap->fresh()->reviewed_by);

        // Approving a version is what makes it live.
        $this->assertSame('Published', $version->fresh()->status);
    }

    /**
     * One item failing does not cost the manager the rest of the folder.
     *
     * A version whose modules have no material cannot publish, which is a
     * normal thing to hit here. Rolling the batch back would mean pressing
     * the button again to find out which one it was.
     */
    public function test_approving_many_keeps_what_it_could_and_names_what_it_could_not(): void
    {
        $roadmap = Roadmap::where('title', 'Test Roadmap')->sole();
        $roadmap->update(['review_status' => 'pending']);

        // Its one module has no approved material and is not coming-soon, so
        // the publish gate refuses the version.
        $version = $roadmap->versions()->sole();
        $version->update(['review_status' => 'pending']);
        $this->module->update(['coming_soon' => false]);

        $this->actingAs($this->manager, 'sanctum')
            ->postJson('/api/material/approve-many', [
                'items' => [
                    ['type' => 'roadmaps', 'id' => $roadmap->id],
                    ['type' => 'roadmap-versions', 'id' => $version->id],
                ],
            ])
            ->assertOk()
            ->assertJsonPath('approved', 1)
            ->assertJsonCount(1, 'failed')
            ->assertJsonPath('failed.0.type', 'roadmap-versions');

        // The one that could be approved was, and stayed approved.
        $this->assertSame('approved', $roadmap->fresh()->review_status);
        $this->assertSame('pending', $version->fresh()->review_status);
    }

    public function test_a_student_may_not_approve_a_folder(): void
    {
        $roadmap = Roadmap::where('title', 'Test Roadmap')->sole();
        $roadmap->update(['review_status' => 'pending']);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/material/approve-many', [
                'items' => [['type' => 'roadmaps', 'id' => $roadmap->id]],
            ])
            ->assertStatus(403);

        $this->assertSame('pending', $roadmap->fresh()->review_status);
    }

    /** A roadmap three deep still files under the subject at the top. */
    public function test_a_nested_roadmap_files_under_its_top_level_subject(): void
    {
        $subject = Roadmap::whereNull('parent_roadmap_id')->sole();
        $path    = Roadmap::where('title', 'Test Roadmap')->sole();

        // subject -> Test Roadmap -> React, the depth the builder produces.
        Roadmap::create([
            'title'             => 'React',
            'description'       => 'Two levels below the subject.',
            'review_status'     => 'pending',
            'parent_roadmap_id' => $path->id,
        ]);

        $this->actingAs($this->manager, 'sanctum')
            ->getJson('/api/material/pending')
            ->assertStatus(200)
            ->assertJsonPath('roadmaps.0.title', 'React')
            ->assertJsonPath('roadmaps.0.subject', $subject->title)
            ->assertJsonPath('roadmaps.0.roadmap', 'React');
    }
}
