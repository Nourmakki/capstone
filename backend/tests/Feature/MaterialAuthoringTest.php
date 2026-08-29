<?php

namespace Tests\Feature;

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
}
