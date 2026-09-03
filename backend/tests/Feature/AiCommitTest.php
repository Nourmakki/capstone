<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Services\AiRoadmapCommitService;
use App\Services\AiWorkflowService;
use GuzzleHttp\Psr7\Response as GuzzleResponse;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Response as ClientResponse;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class AiCommitTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    /**
     * Nothing here may reach the AI service.
     *
     * Committing starts a learning run for every module it invents. The queue
     * driver under test is `sync`, so a dispatched job runs immediately, and
     * with nothing faked those runs went out over the network to whatever was
     * listening on the configured port - a real service, generating real
     * material, on every run of this file. It was the reason these tests took
     * five minutes.
     *
     * preventStrayRequests is the belt to the queue's braces: it turns any
     * unfaked call into a failure that names the URL, rather than a silent
     * request that quietly succeeds.
     */
    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function service(): AiRoadmapCommitService
    {
        return app(AiRoadmapCommitService::class);
    }

    /** One of her JSON replies, as the client would hand it back. */
    private function aiResponse(array $body): ClientResponse
    {
        return new ClientResponse(
            new GuzzleResponse(200, ['Content-Type' => 'application/json'], json_encode($body))
        );
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

    /**
     * The same commit, but through the endpoint a manager actually presses.
     *
     * Every other test in this file calls the service directly, which left the
     * controller wrapping it uncovered - and it was broken: the closure passed
     * to proxy() used $request without capturing it, so every real commit died
     * on "Undefined variable $request". While proxy() still caught Throwable
     * that surfaced as "the AI service is not reachable", so the one visible
     * symptom pointed at a service that was answering fine.
     *
     * Worth a route-level test rather than a second service-level one: what
     * failed here was the wiring, and only a request exercises the wiring.
     */
    public function test_a_manager_can_commit_a_proposal_through_the_endpoint(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);
        $workflow = $this->workflowFor($live);
        $manager  = $this->makeManager();

        $temp = 900;

        // Her service is read again at commit time, so the proposal has to
        // come from it rather than from the row we stored. Stubbed at the
        // service rather than with Http::fake(): setUp() registers a catch-all
        // fake, and that one answers first, so a URL stub added here never
        // gets consulted.
        $this->mock(AiWorkflowService::class, function ($mock) use ($workflow, $temp, $nodes) {
            $mock->shouldReceive('getWorkflow')
                ->with($workflow->thread_id)
                ->andReturn($this->aiResponse([
                    'thread_id' => $workflow->thread_id,
                    'status'    => 'ready_for_commit',
                    'data'      => [
                        'node_suggestions_with_ids' => [$this->suggestion($temp, 'Testing')],
                        'placement_suggestions'     => [$this->placement($temp, 'Testing', [$nodes[1]->id])],
                        'existing_edges_to_remove'  => [],
                    ],
                ]));
        });

        $response = $this->actingAs($manager)
            ->postJson("/api/ai-workflows/{$workflow->id}/commit");

        // The assertion that matters. With the closure not capturing $request,
        // this was an error every single time, for every proposal.
        $response->assertCreated();

        $committed = RoadmapVersion::find($response->json('version_id'));

        $this->assertNotNull($committed);
        $this->assertNotSame($live->id, $committed->id);
        $this->assertSame($committed->id, $workflow->fresh()->committed_version_id);

        // The reviewer $request->user() carries is only stamped on a version
        // that can go live, and this one cannot: the module just added has no
        // material yet, so it stays a pending draft. goLive covers that.
    }

    /**
     * A committed proposal stays committed when the page is reopened.
     *
     * Her thread reports ready_for_commit for ever - nothing tells her
     * service the proposal was written into a version - and show() copied
     * her status straight over ours. So every reload undid the commit in our
     * row, and the page tagged the proposal "ready for commit" above a
     * button saying it had already been committed.
     */
    public function test_reopening_a_committed_proposal_keeps_it_committed(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);
        $workflow = $this->workflowFor($live);
        $manager  = $this->makeManager();
        $temp     = 900;

        // What her service says, before and after the commit alike.
        $hers = $this->aiResponse([
            'thread_id' => $workflow->thread_id,
            'status'    => 'ready_for_commit',
            'data'      => [
                'node_suggestions_with_ids' => [$this->suggestion($temp, 'Testing')],
                'placement_suggestions'     => [$this->placement($temp, 'Testing', [$nodes[1]->id])],
                'existing_edges_to_remove'  => [],
            ],
        ]);

        $this->mock(AiWorkflowService::class, function ($mock) use ($hers) {
            $mock->shouldReceive('getWorkflow')->andReturn($hers);
        });

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/ai-workflows/{$workflow->id}/commit")
            ->assertCreated();

        $this->assertSame('committed', $workflow->fresh()->status);

        // Reopening it is what used to undo the commit.
        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/ai-workflows/{$workflow->id}")
            ->assertOk()
            ->assertJsonPath('status', 'committed');

        $this->assertSame('committed', $workflow->fresh()->status);
        $this->assertNotNull($workflow->fresh()->committed_version_id);
    }

    /** And it cannot be sent back through review afterwards. */
    public function test_a_committed_proposal_cannot_be_reviewed_again(): void
    {
        ['version' => $live] = $this->makeRoadmap(['Basics', 'Components']);

        $workflow = $this->workflowFor($live);
        $workflow->update(['committed_version_id' => $live->id, 'status' => 'committed']);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/ai-workflows/{$workflow->id}/resume", ['action' => 'approve'])
            ->assertStatus(409);

        $this->assertSame('committed', $workflow->fresh()->status);
    }

    /**
     * A roadmap with a choice on it survives being committed onto.
     *
     * The clone copied node_id and nothing else, so every choice arrived in
     * the new version as a rail module with no prerequisites - and the graph
     * rules then refused the commit for having four starting modules. The
     * proposal was blamed for damage the copy had done, and no AI proposal
     * could be committed onto any roadmap offering a choice.
     */
    public function test_committing_onto_a_roadmap_with_choices_keeps_them(): void
    {
        ['version' => $live, 'nodes' => $nodes, 'versionNodes' => $versionNodes]
            = $this->makeRoadmap(['Basics', 'Pick a CI Tool']);

        $pick = $versionNodes[1];

        foreach ([['Jenkins', true], ['GitLab CI', false]] as [$title, $recommended]) {
            $node = Node::create([
                'title'       => $title,
                'description' => "About {$title}.",
                'difficulty'  => 'beginner',
                'status'      => 'approved',
            ]);

            VersionNode::create([
                'roadmap_version_id'     => $live->id,
                'node_id'                => $node->id,
                'parent_version_node_id' => $pick->id,
                'is_recommended'         => $recommended,
                'recommendation_reason'  => $recommended ? 'Most widely used.' : null,
            ]);
        }

        $temp = 900;

        // Would have thrown "A roadmap must have exactly one starting module".
        $committed = $this->service()->commit($this->workflowFor($live), [
            'node_suggestions_with_ids' => [$this->suggestion($temp, 'Containers')],
            'placement_suggestions'     => [$this->placement($temp, 'Containers', [$nodes[0]->id])],
            'existing_edges_to_remove'  => [],
        ]);

        $rail = VersionNode::with('node')
            ->where('roadmap_version_id', $committed->id)
            ->whereNull('parent_version_node_id')
            ->get();

        // Basics, Pick a CI Tool, Containers - and not the two choices.
        $this->assertCount(3, $rail);
        $this->assertNotContains('Jenkins', $rail->map(fn ($n) => $n->node->title)->all());

        $newPick = $rail->firstWhere(fn ($n) => $n->node->title === 'Pick a CI Tool');
        $choices = $newPick->choices()->with('node')->get();

        $this->assertCount(2, $choices);
        $this->assertSame('choice', $newPick->kind());

        // The choices hang off the new version's node, not the old one's.
        foreach ($choices as $choice) {
            $this->assertSame($committed->id, $choice->roadmap_version_id);
        }

        $jenkins = $choices->firstWhere(fn ($c) => $c->node->title === 'Jenkins');

        $this->assertTrue((bool) $jenkins->is_recommended);
        $this->assertSame('Most widely used.', $jenkins->recommendation_reason);
    }

    /** The other columns the copy used to drop. */
    public function test_committing_carries_a_module_link_and_its_coming_soon_mark(): void
    {
        ['version' => $live, 'nodes' => $nodes, 'versionNodes' => $versionNodes]
            = $this->makeRoadmap(['Basics', 'Advanced']);

        $other = \App\Models\Roadmap::create([
            'title'         => 'A roadmap of its own',
            'description'   => 'Opened by a module.',
            'review_status' => 'approved',
        ]);

        $versionNodes[0]->update(['links_to_roadmap_id' => $other->id, 'stage' => 2]);
        $versionNodes[1]->update(['coming_soon' => true]);

        $temp = 900;

        $committed = $this->service()->commit($this->workflowFor($live), [
            'node_suggestions_with_ids' => [$this->suggestion($temp, 'Extra')],
            'placement_suggestions'     => [$this->placement($temp, 'Extra', [$nodes[1]->id])],
            'existing_edges_to_remove'  => [],
        ]);

        $copied = VersionNode::with('node')
            ->where('roadmap_version_id', $committed->id)
            ->get()
            ->keyBy(fn ($n) => $n->node->title);

        $this->assertSame($other->id, $copied['Basics']->links_to_roadmap_id);
        $this->assertSame('roadmap', $copied['Basics']->kind());
        $this->assertSame(2, (int) $copied['Basics']->stage);
        $this->assertTrue((bool) $copied['Advanced']->coming_soon);
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