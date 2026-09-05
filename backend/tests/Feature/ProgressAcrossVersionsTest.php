<?php

namespace Tests\Feature;

use App\Models\CompletedNode;
use App\Models\RoadmapVersion;
use App\Models\Student;
use App\Models\UserRoadmapProgress;
use App\Models\VersionNode;
use App\Services\VersionCloner;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Finishing a module finishes it, whichever version it was read in.
 *
 * A completion is recorded against the module - nodes.id - and cloning a
 * version carries that id across, so the same module really is the same
 * module in v2. Everything that read progress was asking the wrong question
 * of it: which modules did this student finish *in this version*. Publishing
 * v2 of a roadmap somebody was halfway through therefore emptied it in front
 * of them, and asked them to do it all again.
 */
class ProgressAcrossVersionsTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    /**
     * Build v1 with the given modules, finish the named ones, then clone it
     * into a published v2.
     *
     * @return array{student:\App\Models\User, v1:RoadmapVersion, v2:RoadmapVersion, nodes:array}
     */
    private function twoVersions(array $titles, array $finish): array
    {
        ['version' => $v1, 'nodes' => $nodes] = $this->makeRoadmap($titles);

        $user    = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $v1->id,
            'status'             => 'in_progress',
        ]);

        foreach ($finish as $index) {
            CompletedNode::create([
                'user_roadmap_progress_id' => $progress->id,
                'node_id'                  => $nodes[$index]->id,
            ]);
        }

        $v2 = RoadmapVersion::create([
            'roadmap_id'     => $v1->roadmap_id,
            'version_number' => '2',
            'status'         => 'Published',
            'review_status'  => 'approved',
        ]);

        app(VersionCloner::class)->copy($v1, $v2);

        $v1->update(['status' => 'Archived']);

        return ['student' => $user, 'v1' => $v1, 'v2' => $v2, 'nodes' => $nodes];
    }

    // -----------------------------------------------------------------
    // The reported behaviour
    // -----------------------------------------------------------------

    public function test_a_module_finished_in_v1_reads_as_finished_in_v2(): void
    {
        // Basics and Components done; Router not.
        ['student' => $user, 'v2' => $v2] = $this->twoVersions(
            ['Basics', 'Components', 'Router'],
            [0, 1]
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roadmaps/{$v2->roadmap_id}/student/{$user->id}?version={$v2->id}")
            ->assertOk();

        $completedIds = $response->json('completed');

        $titlesById = collect($response->json('nodes'))
            ->mapWithKeys(fn ($n) => [$n['node']['id'] => $n['node']['title']]);

        $completedTitles = collect($completedIds)->map(fn ($id) => $titlesById[$id] ?? null)
            ->filter()
            ->sort()
            ->values()
            ->all();

        $this->assertSame(['Basics', 'Components'], $completedTitles);
    }

    /**
     * And the next module is open because of it, rather than locked behind a
     * prerequisite the student has already done.
     */
    public function test_the_module_after_one_finished_in_v1_is_unlocked_in_v2(): void
    {
        ['student' => $user, 'v2' => $v2, 'nodes' => $nodes] = $this->twoVersions(
            ['Basics', 'Components', 'Router'],
            [0]
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roadmaps/{$v2->roadmap_id}/student/{$user->id}?version={$v2->id}")
            ->assertOk();

        // Components' prerequisite is Basics, which is done in v1, so it is
        // where the student is picked up.
        $this->assertSame($nodes[1]->id, $response->json('current_node'));
    }

    /** A module nobody finished stays unfinished. */
    public function test_a_module_never_finished_is_not_carried_over(): void
    {
        ['student' => $user, 'v2' => $v2, 'nodes' => $nodes] = $this->twoVersions(
            ['Basics', 'Components'],
            [0]
        );

        $response = $this->actingAs($user, 'sanctum')
            ->getJson("/api/roadmaps/{$v2->roadmap_id}/student/{$user->id}?version={$v2->id}")
            ->assertOk();

        $this->assertNotContains($nodes[1]->id, $response->json('completed'));
    }

    // -----------------------------------------------------------------
    // Where it must not leak
    // -----------------------------------------------------------------

    /**
     * A module can sit in two roadmaps. Finishing it in one is not a claim to
     * have finished the other.
     */
    public function test_completion_does_not_leak_between_roadmaps(): void
    {
        ['version' => $first, 'nodes' => $firstNodes] = $this->makeRoadmap(['Shared', 'Other']);

        $user    = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->first();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $first->id,
            'status'             => 'in_progress',
        ]);

        CompletedNode::create([
            'user_roadmap_progress_id' => $progress->id,
            'node_id'                  => $firstNodes[0]->id,
        ]);

        // A second roadmap that happens to contain the same module.
        ['version' => $second] = $this->makeRoadmap(['Unrelated']);

        VersionNode::create([
            'roadmap_version_id' => $second->id,
            'node_id'            => $firstNodes[0]->id,
        ]);

        $completed = UserRoadmapProgress::completedNodeIdsFor($student->id, $second->roadmap_id);

        $this->assertSame([], $completed);
    }

    /**
     * A module finished in v1 and dropped from v2 is still finished, and
     * still no part of what v2 asks for. Counting it would put a student past
     * 100% of a roadmap they are in the middle of.
     */
    public function test_a_module_dropped_from_the_new_version_does_not_inflate_progress(): void
    {
        ['student' => $user, 'v2' => $v2, 'nodes' => $nodes] = $this->twoVersions(
            ['Basics', 'Components', 'Router'],
            [0, 1, 2]
        );

        // v2 drops Router, and the edge that pointed at it.
        VersionNode::where('roadmap_version_id', $v2->id)
            ->where('node_id', $nodes[2]->id)
            ->delete();

        $student = Student::where('user_id', $user->id)->first();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $v2->id,
            'status'             => 'in_progress',
        ]);

        $remaining = VersionNode::where('roadmap_version_id', $v2->id)
            ->where('node_id', $nodes[1]->id)
            ->first();

        $response = $this->actingAs($user, 'sanctum')
            ->postJson('/api/progress/complete-node', ['version_node_id' => $remaining->id]);

        $response->assertOk();

        $this->assertSame(2, $response->json('total_nodes'));
        $this->assertSame(2, $response->json('completed_count'));
        $this->assertSame(100, $response->json('percent_complete'));
    }

    /** The profile agrees with the roadmap, and says it once. */
    public function test_the_profile_lists_a_roadmap_once_and_counts_every_version(): void
    {
        ['student' => $user, 'v1' => $v1, 'v2' => $v2] = $this->twoVersions(
            ['Basics', 'Components', 'Router', 'Forms'],
            [0, 1]
        );

        $student = Student::where('user_id', $user->id)->first();

        // Reading v2 now, so a progress row against it as well.
        UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $v2->id,
            'status'             => 'in_progress',
        ]);

        $response = $this->actingAs($user, 'sanctum')
            ->getJson('/api/profile')
            ->assertOk();

        $rows = collect($response->json('roadmaps'))
            ->where('roadmap_id', $v2->roadmap_id)
            ->values();

        $this->assertCount(1, $rows, 'one line per roadmap, not per version');
        $this->assertSame(2, $rows[0]['completed']);
        $this->assertSame(4, $rows[0]['total']);
        $this->assertSame(50, $rows[0]['percent']);
    }
}
