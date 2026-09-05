<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use App\Services\AiRoadmapCommitService;
use App\Services\RailOrder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Where a module sits on the rail.
 *
 * The order is the graph's: a module comes after everything it needs. Nothing
 * stored it as a number, and nothing read the edges for it either - the rail
 * came back in the order the rows were written. That looked correct for a
 * roadmap built in one go, and hid the bug completely: only adding a module
 * to an existing roadmap shows it, and then the new module reads as the last
 * step of the roadmap no matter where its placement said to put it.
 */
class RailOrderTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    private function order(RailOrder $railOrder, array $titles, array $prerequisiteTitles = []): array
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap($titles);

        $idFor = [];

        foreach ($titles as $index => $title) {
            $idFor[$title] = $nodes[$index]->id;
        }

        // makeRoadmap chains its modules; these tests state the graph
        // themselves, so start from no edges at all.
        \App\Models\VersionEdge::where('roadmap_version_id', $version->id)->delete();

        $prerequisites = [];

        foreach ($prerequisiteTitles as $title => $needs) {
            foreach ($needs as $need) {
                $prerequisites[$idFor[$title]][] = $idFor[$need];
            }
        }

        $rail = VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->get();

        return $railOrder->sort($rail, $prerequisites)
            ->map(fn ($vn) => $vn->node->title)
            ->all();
    }

    // -----------------------------------------------------------------
    // The reported bug, end to end
    // -----------------------------------------------------------------

    /**
     * A manager reads "after Components, before Router" on the proposal, and
     * that is where the module has to be once it is published.
     *
     * The edges were always written correctly. What was missing was anything
     * reading them for position, so the module appeared last.
     */
    public function test_a_module_placed_mid_roadmap_is_published_in_that_position(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components', 'Router']);

        $workflow = RoadmapAiWorkflow::create([
            'roadmap_version_id' => $live->id,
            'thread_id'          => 'test-rail-order',
            'status'             => 'ready_for_commit',
        ]);

        $temp = 900;

        $committed = app(AiRoadmapCommitService::class)->commit($workflow, [
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
                'direct_prerequisite_node_ids'     => [$nodes[1]->id],
                'direct_successor_node_ids'        => [$nodes[2]->id],
                'recommended_predecessor_node_ids' => [],
                'explanation'                      => 'Because.',
            ]],
            'existing_edges_to_remove' => [
                ['from_node_id' => $nodes[1]->id, 'to_node_id' => $nodes[2]->id],
            ],
        ]);

        // The new module's row is the last one written, so before this fix it
        // came back last however plainly the placement read.
        $newest = VersionNode::where('roadmap_version_id', $committed->id)
            ->orderByDesc('id')
            ->with('node')
            ->first();

        $this->assertSame('Testing', $newest->node->title, 'the new module is the last row written');

        $student = $this->makeStudent();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$committed->roadmap_id}/student/{$student->id}?version={$committed->id}")
            ->assertOk();

        $titles = collect($response->json('nodes'))->pluck('node.title')->all();

        $this->assertSame(['Basics', 'Components', 'Testing', 'Router'], $titles);
    }

    // -----------------------------------------------------------------
    // The ordering itself
    // -----------------------------------------------------------------

    public function test_a_chain_is_read_in_its_own_order(): void
    {
        $order = $this->order(app(RailOrder::class), ['One', 'Two', 'Three'], [
            'Two'   => ['One'],
            'Three' => ['Two'],
        ]);

        $this->assertSame(['One', 'Two', 'Three'], $order);
    }

    /**
     * A chain stated backwards still reads forwards. This is the one that
     * fails on row order alone, whatever the rows happen to be.
     */
    public function test_a_chain_written_backwards_still_reads_forwards(): void
    {
        $order = $this->order(app(RailOrder::class), ['Third', 'Second', 'First'], [
            'Second' => ['First'],
            'Third'  => ['Second'],
        ]);

        $this->assertSame(['First', 'Second', 'Third'], $order);
    }

    /** Nothing orders these, so nothing should move. */
    public function test_modules_the_graph_says_nothing_about_keep_their_order(): void
    {
        $order = $this->order(app(RailOrder::class), ['Alpha', 'Beta', 'Gamma']);

        $this->assertSame(['Alpha', 'Beta', 'Gamma'], $order);
    }

    /** A branch: both sides come after the module that opens them. */
    public function test_a_branch_comes_after_the_module_that_opens_it(): void
    {
        $order = $this->order(app(RailOrder::class), ['Start', 'Left', 'Right', 'End'], [
            'Left'  => ['Start'],
            'Right' => ['Start'],
            'End'   => ['Left', 'Right'],
        ]);

        $this->assertSame(['Start', 'Left', 'Right', 'End'], $order);
    }

    /**
     * A loop cannot be ordered, and VersionGraphRules refuses to write one -
     * but a version from before that rule exists, and has to render. Out of
     * order beats a page that never loads, or a request that never returns.
     */
    public function test_a_loop_still_returns_every_module_once(): void
    {
        $order = $this->order(app(RailOrder::class), ['A', 'B', 'C'], [
            'A' => ['C'],
            'B' => ['A'],
            'C' => ['B'],
        ]);

        sort($order);

        $this->assertSame(['A', 'B', 'C'], $order);
    }

    /**
     * An edge can name a module this version does not have - a choice, or one
     * dropped from the rail. Waiting on it would strand everything behind it.
     */
    public function test_a_prerequisite_outside_the_rail_does_not_strand_a_module(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['One', 'Two']);

        $rail = VersionNode::with('node')
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->get();

        $order = app(RailOrder::class)
            ->sort($rail, [$nodes[0]->id => [99999], $nodes[1]->id => [$nodes[0]->id]])
            ->map(fn ($vn) => $vn->node->title)
            ->all();

        $this->assertSame(['One', 'Two'], $order);
    }

    // -----------------------------------------------------------------
    // Stages
    // -----------------------------------------------------------------

    /**
     * Stages are a grouping a manager adds here; the AI knows nothing about
     * them. A module spliced into the middle of "Fundamentals" that carried
     * no stage would break the group in half when the rail was drawn.
     */
    public function test_a_placed_module_joins_the_stage_it_is_placed_into(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components', 'Router']);

        VersionNode::where('roadmap_version_id', $live->id)
            ->whereIn('node_id', [$nodes[0]->id, $nodes[1]->id])
            ->update(['stage' => 1, 'stage_title' => 'Fundamentals']);

        $workflow = RoadmapAiWorkflow::create([
            'roadmap_version_id' => $live->id,
            'thread_id'          => 'test-rail-stage',
            'status'             => 'ready_for_commit',
        ]);

        $temp = 901;

        $committed = app(AiRoadmapCommitService::class)->commit($workflow, [
            'node_suggestions_with_ids' => [[
                'id'             => $temp,
                'title'          => 'Props',
                'description'    => 'About Props.',
                'difficulty'     => 'beginner',
                'based_on_trend' => 'n/a',
            ]],
            'placement_suggestions' => [[
                'suggested_node_id'                => $temp,
                'suggested_node_title'             => 'Props',
                'direct_prerequisite_node_ids'     => [$nodes[1]->id],
                'direct_successor_node_ids'        => [$nodes[2]->id],
                'recommended_predecessor_node_ids' => [],
                'explanation'                      => 'Because.',
            ]],
            'existing_edges_to_remove' => [
                ['from_node_id' => $nodes[1]->id, 'to_node_id' => $nodes[2]->id],
            ],
        ]);

        $props = VersionNode::where('roadmap_version_id', $committed->id)
            ->whereHas('node', fn ($q) => $q->where('title', 'Props'))
            ->first();

        $this->assertSame(1, $props->stage);
        $this->assertSame('Fundamentals', $props->stage_title);
    }

    /** A roadmap nobody has grouped stays ungrouped. */
    public function test_a_placed_module_carries_no_stage_when_the_roadmap_has_none(): void
    {
        ['version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $workflow = RoadmapAiWorkflow::create([
            'roadmap_version_id' => $live->id,
            'thread_id'          => 'test-rail-nostage',
            'status'             => 'ready_for_commit',
        ]);

        $temp = 902;

        $committed = app(AiRoadmapCommitService::class)->commit($workflow, [
            'node_suggestions_with_ids' => [[
                'id'             => $temp,
                'title'          => 'Hooks',
                'description'    => 'About Hooks.',
                'difficulty'     => 'beginner',
                'based_on_trend' => 'n/a',
            ]],
            'placement_suggestions' => [[
                'suggested_node_id'                => $temp,
                'suggested_node_title'             => 'Hooks',
                'direct_prerequisite_node_ids'     => [$nodes[1]->id],
                'direct_successor_node_ids'        => [],
                'recommended_predecessor_node_ids' => [],
                'explanation'                      => 'Because.',
            ]],
            'existing_edges_to_remove' => [],
        ]);

        $hooks = VersionNode::where('roadmap_version_id', $committed->id)
            ->whereHas('node', fn ($q) => $q->where('title', 'Hooks'))
            ->first();

        $this->assertNull($hooks->stage);
    }
}
