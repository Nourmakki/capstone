<?php

namespace Tests\Feature;

use App\Models\NodeLearningWorkflow;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Tutorial;
use App\Models\ExternalResources;
use App\Services\AiLearningCommitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Writing an approved learning run into the module it belongs to.
 *
 * This path had no tests, and it shipped a fault that looked like success:
 * every mapper returns 0 for a payload shape it does not recognise, so a run
 * whose wording had moved committed four zeros, marked itself done, and
 * answered "Material added to the module". The manager then went hunting for
 * material that was never written - with the run marked committed, so it
 * could not be tried again.
 */
class LearningCommitTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private function service(): AiLearningCommitService
    {
        return app(AiLearningCommitService::class);
    }

    private function workflow(): NodeLearningWorkflow
    {
        ['versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        return NodeLearningWorkflow::create([
            'version_node_id' => $nodes[0]->id,
            'thread_id'       => 'learn-1',
            'status'          => 'ready_for_commit',
        ]);
    }

    /** One of her finished runs, shaped as the agents return it. */
    private function readyData(array $overrides = []): array
    {
        return array_replace([
            'tutorial_result' => [
                'status'   => 'ready',
                'tutorial' => [
                    'title'            => 'React in 100 Minutes',
                    'url'              => 'https://youtube.com/watch?v=abc',
                    'selection_reason' => 'Covers every outcome on the module.',
                    'channel_name'     => 'Fireship',
                ],
                'coverage' => ['summary' => 'Components, state and effects.'],
            ],
            'quiz_result' => [
                'tutorial_resource_id' => 'res-1',
                'questions' => [[
                    'question'       => 'What is JSX?',
                    'options'        => [
                        ['key' => 'A', 'text' => 'A templating syntax'],
                        ['key' => 'B', 'text' => 'A database'],
                        ['key' => 'C', 'text' => 'A CSS framework'],
                        ['key' => 'D', 'text' => 'A test runner'],
                    ],
                    'correct_option' => 'A',
                    'correct_answer_explanation' => 'It compiles to function calls.',
                ]],
            ],
            'project_result' => [
                'title'    => 'Build a counter',
                'overview' => 'A small component with state.',
                'tasks'    => ['Render a number', 'Add a button'],
            ],
            'external_resources_result' => [
                'resources' => [[
                    'title'         => 'React docs',
                    'url'           => 'https://react.dev',
                    'resource_type' => 'official_documentation',
                ]],
            ],
        ], $overrides);
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function test_a_finished_run_writes_every_part_onto_the_module(): void
    {
        $workflow = $this->workflow();

        $created = $this->service()->commit($workflow, $this->readyData());

        $this->assertSame(
            ['tutorial' => 1, 'quiz' => 1, 'project' => 1, 'resources' => 1],
            $created
        );

        $node = $workflow->version_node_id;

        $this->assertSame(1, Tutorial::where('version_node_id', $node)->count());
        $this->assertSame(1, Quiz::where('version_node_id', $node)->count());
        $this->assertSame(1, Project::where('version_node_id', $node)->count());
        $this->assertSame(1, ExternalResources::where('version_node_id', $node)->count());

        // Everything lands pending - it reaches students through the queue.
        $this->assertSame('pending', Tutorial::sole()->review_status);
        $this->assertSame('pending', Quiz::sole()->review_status);

        // The A/B/C/D key is resolved to the answer text our grader compares.
        $this->assertSame('A templating syntax', QuizQuestion::sole()->answer);

        $workflow->refresh();
        $this->assertSame('committed', $workflow->status);
        $this->assertNotNull($workflow->committed_at);
    }

    // -----------------------------------------------------------------
    // The fault this file exists for
    // -----------------------------------------------------------------

    /**
     * A run that yields nothing must say so, not report success.
     */
    public function test_a_run_that_writes_nothing_is_refused(): void
    {
        $workflow = $this->workflow();

        $this->expectException(RuntimeException::class);

        $this->service()->commit($workflow, [
            'tutorial_result'           => ['status' => 'learning_failed'],
            'quiz_result'               => ['questions' => []],
            'project_result'            => [],
            'external_resources_result' => ['resources' => []],
        ]);
    }

    /** And it stays open, so it can be committed again once fixed. */
    public function test_a_refused_run_is_left_uncommitted(): void
    {
        $workflow = $this->workflow();

        try {
            $this->service()->commit($workflow, []);
        } catch (RuntimeException) {
            // asserted below
        }

        $workflow->refresh();

        $this->assertNotSame('committed', $workflow->status);
        $this->assertNull($workflow->committed_at);
        $this->assertSame(0, Tutorial::count());
    }

    /**
     * The wording moving is the thing that caused this, so the message has to
     * name it - "no tutorial" would send someone looking in the wrong place.
     */
    public function test_the_refusal_names_a_tutorial_status_it_does_not_know(): void
    {
        $workflow = $this->workflow();

        try {
            $this->service()->commit($workflow, [
                // What her tutorial agent briefly returned after the rename.
                'tutorial_result' => ['status' => 'ready_for_commit', 'tutorial' => ['title' => 'x']],
            ]);

            $this->fail('An empty commit should have been refused.');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('ready_for_commit', $e->getMessage());
            $this->assertStringContainsString('committed again', $e->getMessage());
        }
    }

    /**
     * A run legitimately without a tutorial still commits.
     *
     * Her tutorial agent reports learning_failed when it cannot find a good
     * video, and the quiz and project are still worth having.
     */
    public function test_a_run_missing_only_its_tutorial_still_commits(): void
    {
        $workflow = $this->workflow();

        $created = $this->service()->commit($workflow, $this->readyData([
            'tutorial_result' => ['status' => 'learning_failed'],
        ]));

        $this->assertSame(0, $created['tutorial']);
        $this->assertSame(1, $created['quiz']);
        $this->assertSame(1, $created['project']);

        $workflow->refresh();
        $this->assertSame('committed', $workflow->status);
    }

    /** A question whose correct_option names no option is a hard error. */
    public function test_a_question_marking_a_missing_option_is_refused(): void
    {
        $workflow = $this->workflow();

        $this->expectException(RuntimeException::class);

        $this->service()->commit($workflow, $this->readyData([
            'quiz_result' => [
                'questions' => [[
                    'question'       => 'Broken?',
                    'options'        => [['key' => 'A', 'text' => 'Only option']],
                    'correct_option' => 'D',
                ]],
            ],
        ]));
    }
}
