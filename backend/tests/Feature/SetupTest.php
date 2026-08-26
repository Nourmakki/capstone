<?php

namespace Tests\Feature;

use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class SetupTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    public function test_it_builds_users_with_their_role_rows(): void
    {
        $student = $this->makeStudent();

        $this->assertSame('Student', $student->role);
        $this->assertNotNull($student->student);
        $this->assertNotNull($this->makeProfessor()->professor);
        $this->assertSame('MaterialManager', $this->makeManager()->role);
    }

    public function test_it_builds_a_chained_roadmap(): void
    {
        ['version' => $version] = $this->makeRoadmap(['A', 'B', 'C']);

        $this->assertCount(3, VersionNode::where('roadmap_version_id', $version->id)->get());
        $this->assertCount(2, VersionEdge::where('roadmap_version_id', $version->id)->get());
    }

    public function test_the_generated_graph_satisfies_the_single_root_rule(): void
    {
        ['version' => $version] = $this->makeRoadmap(['A', 'B', 'C']);

        // Throws if the graph has a cycle or more than one starting module.
        app(\App\Services\VersionGraphRules::class)->assertValid($version);

        $this->assertTrue(true);
    }
}