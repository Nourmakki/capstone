<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Services\AiRoadmapCommitService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class AiCommitTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private function service(): AiRoadmapCommitService
    {
        return app(AiRoadmapCommitService::class);
    }

    private function workflowFor(RoadmapVersion $version): RoadmapAiWorkflow
    {
        return RoadmapAiWorkflow::create([
            'roadmap_version_id' => $version->id,
            'thread_id'          => 'test-' . $version->id,
            'status'             => 'ready_for_commit',
        ]);
    }

    private function suggestion(int $id, string $title): array
    {
        return [
            'id'             => $id,
            'title'          => $title,
            'description'    => "About {$title}.",
            'difficulty'     => 'advanced',
            'based_on_trend' => 'n/a',
        ];
    }

    private function placement(int $id, string $title, array $prerequisites = [], array $successors = []): array
    {
        return [
            'suggested_node_id'              => $id,
            'suggested_node_title'           => $title,
            'direct_prerequisite_node_ids'   => $prerequisites,
            'direct_successor_node_ids'      => $successors,
            'recommended_predecessor_node_ids' => [],
            'explanation'                    => 'Because.',
        ];
    }

    /** The prerequisites of every module in a version, keyed by node id. */
    private function prerequisites(RoadmapVersion $version): array
    {
        $map = [];

        foreach (VersionEdge::with('edge')->where('roadmap_version_id', $version->id)->get() as $versionEdge) {
            if ($versionEdge->edge) {
                $map[$versionEdge->edge->to_node_id][] = $versionEdge->edge->from_node_id;
            }
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function test_an_approved_proposal_becomes_a_new_draft_version(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components', 'Router']);
        $workflow = $this->workflowFor($live);

        $temp = 900;

        $committed = $this->service()->commit($workflow, [
            'node_suggestions_with_ids' => [$this->suggestion($temp, 'Testing')],
            // Spliced between Components and Router, so the chain stays whole.
            'placement_suggestions'     => [
                $this->placement($temp, 'Testing', [$nodes[1]->id], [$nodes[2]->id]),
            ],
            'existing_edges_to_remove'  => [
                ['from_node_id' => $nodes[1]->id, 'to_node_id' => $nodes[2]->id],
            ],
        ]);

        $this->assertSame('Draft', $committed->status);

        // The column is defaulted by the database, so the freshly created
        // model has no value for it until it is reloaded.
        $this->assertSame('pending', $committed->fresh()->review_status);
        $this->assertNotSame($live->id, $committed->id);

        $modules = VersionNode::with('node')->where('roadmap_version_id', $committed->id)->get();

        $this->assertCount(4, $modules);
        $this->assertContains('Testing', $modules->map(fn ($m) => $m->node->title)->all());

        $newNodeId = Node::where('title', 'Testing')->first()->id;
        $prerequisites = $this->prerequisites($committed);

        $this->assertSame([$nodes[1]->id], $prerequisites[$newNodeId]);
        $this->assertSame([$newNodeId], $prerequisites[$nodes[2]->id]);

        $workflow->refresh();

        $this->assertSame($committed->id, $workflow->committed_version_id);
        $this->assertSame('committed', $workflow->status);
    }

    public function test_the_live_version_is_left_untouched(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);
        $before = $this->prerequisites($live);

        $this->service()->commit($this->workflowFor($live), [
            'node_suggestions_with_ids' => [$this->suggestion(900, 'Testing')],
            'placement_suggestions'     => [$this->placement(900, 'Testing', [$nodes[1]->id])],
            'existing_edges_to_remove'  => [],
        ]);

        $this->assertCount(2, VersionNode::where('roadmap_version_id', $live->id)->get());
        $this->assertSame($before, $this->prerequisites($live));
        $this->assertSame('Published', $live->fresh()->status);
    }

    public function test_the_committed_version_carries_the_material_forward(): void
    {
        ['version' => $live, 'nodes' => $nodes, 'versionNodes' => $versionNodes] = $this->makeRoadmap(['Basics']);

        Tutorial::create([
            'version_node_id' => $versionNodes[0]->id,
            'title'           => 'React Crash Course',
            'url'             => 'https://youtube.com/watch?v=x',
            'description'     => 'An introduction.',
            'source_name'     => 'Traversy',
            'review_status'   => 'approved',
        ]);

        $committed = $this->service()->commit($this->workflowFor($live), [
            'node_suggestions_with_ids' => [$this->suggestion(900, 'Testing')],
            'placement_suggestions'     => [$this->placement(900, 'Testing', [$nodes[0]->id])],
            'existing_edges_to_remove'  => [],
        ]);

        $committedNodes = VersionNode::where('roadmap_version_id', $committed->id)->pluck('id');

        $this->assertSame(1, Tutorial::whereIn('version_node_id', $committedNodes)->count());
    }

    // -----------------------------------------------------------------
    // Resolving ids
    // -----------------------------------------------------------------

    public function test_a_temporary_id_that_collides_with_a_real_node_resolves_to_the_new_module(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        // A node belonging to a different roadmap entirely.
        ['nodes' => $otherNodes] = $this->makeRoadmap(['Python for Data Science']);
        $collidingId = $otherNodes[0]->id;

        // The AI numbers new modules from max(existing) + 1, so its temporary
        // ids collide with real ids from other roadmaps. Membership in
        // node_suggestions_with_ids is the only safe way to tell them apart.
        $committed = $this->service()->commit($this->workflowFor($live), [
            'node_suggestions_with_ids' => [$this->suggestion($collidingId, 'Testing')],
            'placement_suggestions'     => [$this->placement($collidingId, 'Testing', [$nodes[1]->id])],
            'existing_edges_to_remove'  => [],
        ]);

        $titles = VersionNode::with('node')
            ->where('roadmap_version_id', $committed->id)
            ->get()
            ->map(fn ($m) => $m->node->title);

        // Resolving by magnitude would have wired the Python module in.
        $this->assertContains('Testing', $titles->all());
        $this->assertNotContains('Python for Data Science', $titles->all());

        $newNode = Node::where('title', 'Testing')->first();

        $this->assertNotSame($collidingId, $newNode->id);
        $this->assertSame([$nodes[1]->id], $this->prerequisites($committed)[$newNode->id]);
    }

    public function test_a_new_module_may_depend_on_another_new_module(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $committed = $this->service()->commit($this->workflowFor($live), [
            'node_suggestions_with_ids' => [
                $this->suggestion(900, 'Testing'),
                $this->suggestion(901, 'Performance'),
            ],
            'placement_suggestions' => [
                $this->placement(900, 'Testing', [$nodes[0]->id]),
                $this->placement(901, 'Performance', [900]),
            ],
            'existing_edges_to_remove' => [],
        ]);

        $testing     = Node::where('title', 'Testing')->first();
        $performance = Node::where('title', 'Performance')->first();

        $this->assertSame([$testing->id], $this->prerequisites($committed)[$performance->id]);
    }

    // -----------------------------------------------------------------
    // Refusals, and what they leave behind
    // -----------------------------------------------------------------

    #[DataProvider('badProposals')]
    public function test_a_bad_proposal_is_refused_and_leaves_nothing_behind(string $case): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        // A node that exists but belongs to another roadmap.
        ['nodes' => $foreign] = $this->makeRoadmap(['Elsewhere']);

        $proposals = [
            'unplaced' => [
                'node_suggestions_with_ids' => [
                    $this->suggestion(900, 'Testing'),
                    $this->suggestion(901, 'Never placed'),
                ],
                'placement_suggestions'    => [$this->placement(900, 'Testing', [901])],
                'existing_edges_to_remove' => [],
            ],
            'foreign_node' => [
                'node_suggestions_with_ids' => [$this->suggestion(900, 'Testing')],
                'placement_suggestions'     => [$this->placement(900, 'Testing', [$foreign[0]->id])],
                'existing_edges_to_remove'  => [],
            ],
            'loop' => [
                'node_suggestions_with_ids' => [$this->suggestion(900, 'Testing')],
                'placement_suggestions'     => [
                    $this->placement(900, 'Testing', [$nodes[1]->id], [$nodes[0]->id]),
                ],
                'existing_edges_to_remove'  => [],
            ],
            'two_roots' => [
                'node_suggestions_with_ids' => [$this->suggestion(900, 'Unattached')],
                'placement_suggestions'     => [$this->placement(900, 'Unattached')],
                'existing_edges_to_remove'  => [],
            ],
            'nothing_to_do' => [
                'node_suggestions_with_ids' => [],
                'placement_suggestions'     => [],
                'existing_edges_to_remove'  => [],
            ],
        ];

        $versionsBefore = RoadmapVersion::count();
        $nodesBefore    = Node::count();
        $edgesBefore    = VersionEdge::count();

        $workflow = $this->workflowFor($live);

        try {
            $this->service()->commit($workflow, $proposals[$case]);
            $this->fail("The '{$case}' proposal should have been refused.");
        } catch (RuntimeException | ValidationException $e) {
            // Expected.
        }

        // The transaction fails late - after the version, the nodes and the
        // edges are already written - so rollback is the whole point.
        $this->assertSame($versionsBefore, RoadmapVersion::count(), 'a version survived');
        $this->assertSame($nodesBefore, Node::count(), 'a node survived');
        $this->assertSame($edgesBefore, VersionEdge::count(), 'an edge survived');
        $this->assertNull($workflow->fresh()->committed_version_id);
    }

    public static function badProposals(): array
    {
        return [
            'a placement referring to an unplaced suggestion' => ['unplaced'],
            'a prerequisite from another roadmap'             => ['foreign_node'],
            'placements that close a loop'                    => ['loop'],
            'a second starting module'                        => ['two_roots'],
            'a proposal that places nothing'                  => ['nothing_to_do'],
        ];
    }

    // -----------------------------------------------------------------
    // Committing twice
    // -----------------------------------------------------------------

    public function test_a_workflow_cannot_be_committed_twice(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics']);
        $workflow = $this->workflowFor($live);

        $this->service()->commit($workflow, [
            'node_suggestions_with_ids' => [$this->suggestion(900, 'Testing')],
            'placement_suggestions'     => [$this->placement(900, 'Testing', [$nodes[0]->id])],
            'existing_edges_to_remove'  => [],
        ]);

        $versions = RoadmapVersion::count();

        // The route refuses before it ever contacts the AI service, so this
        // needs no stub running.
        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/ai-workflows/{$workflow->id}/commit")
            ->assertStatus(409)
            ->assertJsonPath('message', 'This proposal has already been committed.');

        $this->assertSame($versions, RoadmapVersion::count());
    }

    public function test_only_a_material_manager_may_commit(): void
    {
        ['version' => $live] = $this->makeRoadmap(['Basics']);
        $workflow = $this->workflowFor($live);

        foreach (['makeStudent', 'makeProfessor', 'makeAdmin'] as $maker) {
            $this->actingAs($this->{$maker}(), 'sanctum')
                ->postJson("/api/ai-workflows/{$workflow->id}/commit")
                ->assertForbidden();
        }
    }
}