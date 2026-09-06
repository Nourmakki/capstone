<?php

namespace Tests\Feature;

use App\Jobs\RebuildAssistantIndex;
use App\Models\CompletedNode;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Student;
use App\Models\Tutorial;
use App\Models\UserRoadmapProgress;
use App\Models\VersionNode;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The RAG assistant, from the platform's side.
 *
 * Two halves meet in one answer. The corpus is shared - built once, queried by
 * everyone - so nothing personal and nothing unapproved may enter it. The
 * student half is assembled per request from live rows and never indexed,
 * because a vector store cannot tell one student's question from another's.
 *
 * The failures these guard against are quiet ones: an index that quotes a
 * rejected tutorial to every student, a corpus carrying quiz answers to
 * anyone who asks nicely, an assistant that says a student has done six
 * modules of five.
 */
class AssistantTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
    }

    private function assistantReplies(?array $body = null, int $status = 200): void
    {
        Http::fake([
            '*/ask' => Http::response($body ?? [
                'answer'   => 'Start with React Basics.',
                'sources'  => [['title' => 'React Basics']],
                'grounded' => true,
            ], $status),
        ]);
    }

    // -----------------------------------------------------------------
    // Asking
    // -----------------------------------------------------------------

    public function test_a_student_gets_an_answer(): void
    {
        $this->assistantReplies();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => 'What should I learn next?'])
            ->assertOk()
            ->assertJsonPath('answer', 'Start with React Basics.')
            ->assertJsonPath('grounded', true);
    }

    public function test_an_empty_question_is_refused_before_the_service_is_troubled(): void
    {
        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => ''])
            ->assertStatus(422);

        Http::assertNothingSent();
    }

    /**
     * The assistant being off is not the platform being broken, and the
     * message has to say which - it is a separate service on its own port.
     */
    public function test_the_assistant_being_down_reads_as_the_assistant_being_down(): void
    {
        Http::fake(fn () => throw new \Illuminate\Http\Client\ConnectionException('refused'));

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => 'Anything?'])
            ->assertStatus(503)
            ->assertJsonFragment(['message' => 'The assistant is not running right now. Everything else on the platform still works.']);
    }

    public function test_a_failing_service_is_not_reported_as_an_answer(): void
    {
        $this->assistantReplies(['detail' => 'boom'], 500);

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => 'Anything?'])
            ->assertStatus(502);
    }

    // -----------------------------------------------------------------
    // Who is asking
    // -----------------------------------------------------------------

    /**
     * A professor has no progress, and inventing an empty one would have the
     * assistant talk to them as though they were enrolled.
     */
    public function test_a_professor_is_given_no_student_context(): void
    {
        $this->assistantReplies();

        $this->actingAs($this->makeProfessor(), 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => 'What is on the platform?'])
            ->assertOk();

        Http::assertSent(fn ($request) => $request->data()['student'] === null);
    }

    public function test_a_students_own_progress_travels_with_the_question(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $user    = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $version->id,
            'status'             => 'in_progress',
        ]);

        CompletedNode::create([
            'user_roadmap_progress_id' => $progress->id,
            'node_id'                  => $nodes[0]->id,
        ]);

        $this->assistantReplies();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => 'Where am I?'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $roadmap = $request->data()['student']['roadmaps'][0] ?? null;

            return $roadmap
                && $roadmap['completed_modules'] === 1
                && $roadmap['total_modules'] === 2
                && $roadmap['current_module'] === 'Components';
        });
    }

    /**
     * The count is against this version's rail. A choice a student picked
     * inside a module is a completion but not a step, and a module finished
     * in an older version may not be in this one at all - either way the
     * assistant would say they had done more modules than the roadmap has.
     */
    public function test_completed_never_exceeds_total(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $user    = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $version->id,
            'status'             => 'in_progress',
        ]);

        // Everything on the rail, plus a choice hanging off a module, plus a
        // module that is not in this version at all.
        $rail = VersionNode::where('roadmap_version_id', $version->id)->inRail()->get();

        $choiceNode = \App\Models\Node::create([
            'title' => 'React', 'description' => 'A choice.',
            'difficulty' => 'beginner', 'status' => 'approved',
        ]);

        VersionNode::create([
            'roadmap_version_id'     => $version->id,
            'node_id'                => $choiceNode->id,
            'parent_version_node_id' => $rail->first()->id,
        ]);

        $strayNode = \App\Models\Node::create([
            'title' => 'Dropped', 'description' => 'Gone from this version.',
            'difficulty' => 'beginner', 'status' => 'approved',
        ]);

        foreach ([$nodes[0]->id, $nodes[1]->id, $choiceNode->id, $strayNode->id] as $nodeId) {
            CompletedNode::create([
                'user_roadmap_progress_id' => $progress->id,
                'node_id'                  => $nodeId,
            ]);
        }

        $this->assistantReplies();

        $this->actingAs($user, 'sanctum')
            ->postJson('/api/assistant/ask', ['question' => 'How far am I?'])
            ->assertOk();

        Http::assertSent(function ($request) {
            $roadmap = $request->data()['student']['roadmaps'][0];

            return $roadmap['completed_modules'] === 2
                && $roadmap['total_modules'] === 2;
        });
    }

    // -----------------------------------------------------------------
    // The corpus
    // -----------------------------------------------------------------

    private function corpus(): array
    {
        return $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/assistant/corpus')
            ->assertOk()
            ->json();
    }

    /** Everything the index holds, as one string, for absence checks. */
    private function corpusText(): string
    {
        return json_encode($this->corpus());
    }

    public function test_the_corpus_carries_the_catalogue(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $this->assertStringContainsString('Test Roadmap', $this->corpusText());
    }

    /**
     * A student who can ask the assistant for the answers is being handed
     * them. The questions may be indexed; what is correct may not.
     */
    public function test_quiz_answers_never_enter_the_index(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $node = VersionNode::where('roadmap_version_id', $version->id)->first();

        $quiz = Quiz::create([
            'version_node_id' => $node->id,
            'title'           => 'Check yourself',
            'review_status'   => 'approved',
        ]);

        QuizQuestion::create([
            'quiz_id'  => $quiz->id,
            'question' => 'Which hook holds state?',
            'type'     => 'multiple_choice',
            'options'  => ['useState', 'useEffect', 'useMemo', 'useRef'],
            'answer'   => 'SECRETANSWERUSESTATE',
        ]);

        $this->assertStringNotContainsString('SECRETANSWERUSESTATE', $this->corpusText());
    }

    /**
     * The index is built once and quoted to everyone, so one student's
     * progress in it would be readable by every other.
     */
    public function test_nothing_personal_enters_the_index(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $user    = $this->makeStudent(['name' => 'Zzunique Studentname', 'email' => 'zzunique@capstone.test']);
        $student = Student::where('user_id', $user->id)->first();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $version->id,
            'status'             => 'in_progress',
        ]);

        CompletedNode::create([
            'user_roadmap_progress_id' => $progress->id,
            'node_id'                  => $nodes[0]->id,
        ]);

        $text = $this->corpusText();

        $this->assertStringNotContainsString('Zzunique Studentname', $text);
        $this->assertStringNotContainsString('zzunique@capstone.test', $text);
    }

    /**
     * Unapproved material would be quotable by every student, which is the
     * whole point of it not being approved yet.
     */
    public function test_unapproved_material_stays_out(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics']);

        $node = VersionNode::where('roadmap_version_id', $version->id)->first();

        Tutorial::create([
            'version_node_id' => $node->id,
            'title'           => 'ZZPENDINGTUTORIAL',
            'url'             => 'https://example.com/v',
            'description'     => 'Not approved yet.',
            'source_name'     => 'Some Channel',
            'difficulty'      => 'beginner',
            'review_status'   => 'pending',
        ]);

        Project::create([
            'version_node_id' => $node->id,
            'title'           => 'ZZPENDINGPROJECT',
            'description'     => 'Not approved yet.',
            'difficulty'      => 'beginner',
            'review_status'   => 'pending',
        ]);

        $text = $this->corpusText();

        $this->assertStringNotContainsString('ZZPENDINGTUTORIAL', $text);
        $this->assertStringNotContainsString('ZZPENDINGPROJECT', $text);
    }

    // -----------------------------------------------------------------
    // Keeping the index fresh
    // -----------------------------------------------------------------

    /**
     * uniqueId() only does anything on a job that says it should be unique.
     * Declared without the interface it is a method nobody calls, and
     * approving five things rebuilds the same index five times.
     */
    public function test_the_reindex_job_is_actually_deduplicated(): void
    {
        $this->assertInstanceOf(
            ShouldBeUnique::class,
            new RebuildAssistantIndex(),
            'RebuildAssistantIndex declares uniqueId(), which requires ShouldBeUnique to have any effect'
        );
    }

    /**
     * And its timeout must fit inside the window the queue gives it, or the
     * job is handed to a second worker while the first is still running.
     */
    public function test_a_job_cannot_outlive_the_queues_patience(): void
    {
        $retryAfter = config('queue.connections.database.retry_after');

        foreach ([
            \App\Jobs\RebuildAssistantIndex::class,
            \App\Jobs\AssessSubmission::class,
            \App\Jobs\StartLearningRun::class,
        ] as $job) {
            $timeout = (new \ReflectionClass($job))->getDefaultProperties()['timeout'] ?? 0;

            $this->assertLessThan(
                $retryAfter,
                $timeout,
                "{$job} can run for {$timeout}s but the queue releases it after {$retryAfter}s"
            );
        }
    }
}
