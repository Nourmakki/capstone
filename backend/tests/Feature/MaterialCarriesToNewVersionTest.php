<?php

namespace Tests\Feature;

use App\Models\ExternalResources;
use App\Models\NodeLearningWorkflow;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * A module that already has material keeps it in the next version.
 *
 * Material hangs off version_node_id, and a new version means new
 * version_nodes - so without copying, every module in v2 would arrive empty
 * and a manager would be asked to generate again what they had already
 * approved. Six modules unchanged and one added should be one generation,
 * not seven.
 *
 * These say so for each of the ways a version comes into being, because the
 * copying lives in one place and is easy to leave out of a new caller.
 */
class MaterialCarriesToNewVersionTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    /** Put a full set of material on the first module of a version. */
    private function fillFirstModule(RoadmapVersion $version): VersionNode
    {
        $node = VersionNode::where('roadmap_version_id', $version->id)
            ->orderBy('id')
            ->first();

        Tutorial::create([
            'version_node_id' => $node->id,
            'title'           => 'Watch this',
            'url'             => 'https://example.com/v',
            'description'     => 'It explains the thing.',
            'source_name'     => 'Some Channel',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        $quiz = Quiz::create([
            'version_node_id' => $node->id,
            'title'           => 'Check yourself',
            'review_status'   => 'approved',
        ]);

        QuizQuestion::create([
            'quiz_id'  => $quiz->id,
            'question' => 'Is this carried over?',
            'type'     => 'true_false',
            'options'  => ['True', 'False'],
            'answer'   => 'True',
        ]);

        Project::create([
            'version_node_id' => $node->id,
            'title'           => 'Build it',
            'description'     => 'The brief.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        ExternalResources::create([
            'version_node_id' => $node->id,
            'title'           => 'The docs',
            'url'             => 'https://example.com/docs',
            'type'            => 'documentation',
            'review_status'   => 'approved',
        ]);

        return $node;
    }

    /** What a module has, by kind, for comparing across versions. */
    private function materialOn(VersionNode $node): array
    {
        return [
            'tutorials' => Tutorial::where('version_node_id', $node->id)->pluck('title')->sort()->values()->all(),
            'quizzes'   => Quiz::where('version_node_id', $node->id)->pluck('title')->sort()->values()->all(),
            'projects'  => Project::where('version_node_id', $node->id)->pluck('title')->sort()->values()->all(),
            'resources' => ExternalResources::where('version_node_id', $node->id)->pluck('title')->sort()->values()->all(),
        ];
    }

    private function moduleIn(RoadmapVersion $version, int $nodeId): VersionNode
    {
        return VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $nodeId)
            ->first();
    }

    // -----------------------------------------------------------------

    public function test_a_new_version_made_by_hand_keeps_the_material(): void
    {
        ['version' => $v1] = $this->makeRoadmap(['Basics', 'Components', 'Router']);

        $filled = $this->fillFirstModule($v1);
        $before = $this->materialOn($filled);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$v1->roadmap_id}/versions", [])
            ->assertCreated();

        $v2 = RoadmapVersion::where('roadmap_id', $v1->roadmap_id)
            ->where('id', '!=', $v1->id)
            ->first();

        $sameModule = $this->moduleIn($v2, $filled->node_id);

        $this->assertNotNull($sameModule, 'the module should exist in the new version');
        $this->assertSame($before, $this->materialOn($sameModule));

        // Its own rows, not the old ones - editing v2 must not rewrite v1.
        $this->assertNotSame($filled->id, $sameModule->id);

        // And the questions came with the quiz, not just the quiz.
        $newQuiz = Quiz::where('version_node_id', $sameModule->id)->first();
        $this->assertSame(1, QuizQuestion::where('quiz_id', $newQuiz->id)->count());
    }

    /**
     * The case the whole thing is for: a trend proposal adds one module to a
     * roadmap of three. The three keep what they had; only the new one is
     * outstanding.
     */
    public function test_an_ai_proposal_leaves_only_its_new_module_needing_material(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components', 'Router']);

        $filled = $this->fillFirstModule($live);
        $before = $this->materialOn($filled);

        $workflow = \App\Models\RoadmapAiWorkflow::create([
            'roadmap_version_id' => $live->id,
            'thread_id'          => 'carry-over',
            'status'             => 'ready_for_commit',
        ]);

        $temp = 900;

        $committed = app(\App\Services\AiRoadmapCommitService::class)->commit($workflow, [
            'node_suggestions_with_ids' => [[
                'id'             => $temp,
                'title'          => 'Testing',
                'description'    => 'About Testing.',
                'difficulty'     => 'advanced',
                'based_on_trend' => 'n/a',
            ]],
            'placement_suggestions' => [[
                'suggested_node_id'                => $temp,
                'suggested_node_title'             => 'Testing',
                'direct_prerequisite_node_ids'     => [$nodes[2]->id],
                'direct_successor_node_ids'        => [],
                'recommended_predecessor_node_ids' => [],
                'explanation'                      => 'Because.',
            ]],
            'existing_edges_to_remove' => [],
        ]);

        // The module that had material still has it, in the new version.
        $this->assertSame(
            $before,
            $this->materialOn($this->moduleIn($committed, $filled->node_id))
        );

        // And the awaiting list asks for the new module only - not for the
        // three that came across.
        $awaiting = $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/modules-awaiting-material')
            ->assertOk()
            ->json();

        $titles = collect($awaiting['groups'])
            ->flatMap(fn ($group) => collect($group['modules'])->pluck('title'))
            ->all();

        $this->assertContains('Testing', $titles);
        $this->assertNotContains('Basics', $titles, 'Basics kept its material, so it is not outstanding');
    }

    /**
     * And a module that never had any is still asked for, in both versions -
     * carrying material over must not make an empty module look filled.
     */
    public function test_a_module_with_nothing_is_still_outstanding_afterwards(): void
    {
        ['version' => $v1] = $this->makeRoadmap(['Basics', 'Components']);

        $this->fillFirstModule($v1);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$v1->roadmap_id}/versions", [])
            ->assertCreated();

        $titles = collect($this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/modules-awaiting-material')
            ->json('groups'))
            ->flatMap(fn ($group) => collect($group['modules'])->pluck('title'))
            ->all();

        // Components was empty in v1 and is empty in v2 - twice over now.
        $this->assertContains('Components', $titles);
        $this->assertNotContains('Basics', $titles);
    }

    /** A run started against the old version does not stop the copy. */
    public function test_material_carries_even_when_the_old_version_has_a_failed_run(): void
    {
        ['version' => $v1] = $this->makeRoadmap(['Basics']);

        $filled = $this->fillFirstModule($v1);

        NodeLearningWorkflow::create([
            'version_node_id' => $filled->id,
            'thread_id'       => 'an-old-failure',
            'status'          => 'failed',
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$v1->roadmap_id}/versions", [])
            ->assertCreated();

        $v2 = RoadmapVersion::where('roadmap_id', $v1->roadmap_id)
            ->where('id', '!=', $v1->id)
            ->first();

        $this->assertSame(
            $this->materialOn($filled),
            $this->materialOn($this->moduleIn($v2, $filled->node_id))
        );
    }
}
