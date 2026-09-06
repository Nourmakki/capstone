<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The worklist of modules with nothing on them, in folders.
 *
 * The roadmap builder writes a whole subject at once - a dozen roadmaps, each
 * with its own modules - so a flat list of sixty names says nothing about
 * which roadmap a manager is working through. The endpoint groups them, and
 * this is what the shape of that grouping has to be for the page to draw
 * folders from it.
 */
class PendingModulesTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    protected function setUp(): void
    {
        parent::setUp();

        Queue::fake();
        Http::preventStrayRequests();
        Http::fake();
    }

    /** A roadmap under the given subject, with empty modules on a version. */
    private function roadmapWithEmptyModules(Roadmap $subject, string $title, array $moduleTitles): void
    {
        $roadmap = Roadmap::create([
            'title'             => $title,
            'description'       => "About {$title}.",
            'review_status'     => 'approved',
            'parent_roadmap_id' => $subject->id,
        ]);

        $version = RoadmapVersion::create([
            'roadmap_id'     => $roadmap->id,
            'version_number' => '1',
            'status'         => 'Published',
            'review_status'  => 'approved',
        ]);

        foreach ($moduleTitles as $moduleTitle) {
            $node = Node::create([
                'title'       => $moduleTitle,
                'description' => "About {$moduleTitle}.",
                'difficulty'  => 'beginner',
                'status'      => 'approved',
            ]);

            VersionNode::create([
                'roadmap_version_id' => $version->id,
                'node_id'            => $node->id,
            ]);
        }
    }

    public function test_modules_come_back_grouped_by_the_roadmap_that_holds_them(): void
    {
        $subject = Roadmap::create([
            'title'         => 'Web Development',
            'description'   => 'A heading.',
            'review_status' => 'approved',
        ]);

        $this->roadmapWithEmptyModules($subject, 'Frontend', ['HTML', 'CSS', 'React']);
        $this->roadmapWithEmptyModules($subject, 'Backend', ['PHP', 'SQL']);

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/modules-awaiting-material')
            ->assertOk();

        $this->assertSame(5, $response->json('total'));

        $groups = collect($response->json('groups'));

        // A list of groups, which is what the page maps over to draw folders.
        // Keyed by roadmap name instead, it arrives as an object and the map
        // draws nothing at all.
        $this->assertIsList($response->json('groups'));
        $this->assertCount(2, $groups);

        $byRoadmap = $groups->keyBy('roadmap');

        $this->assertEqualsCanonicalizing(['Frontend', 'Backend'], $byRoadmap->keys()->all());
        $this->assertCount(3, $byRoadmap['Frontend']['modules']);
        $this->assertCount(2, $byRoadmap['Backend']['modules']);

        $this->assertEqualsCanonicalizing(
            ['HTML', 'CSS', 'React'],
            collect($byRoadmap['Frontend']['modules'])->pluck('title')->all()
        );
    }

    /** Every module the page needs to draw a row and start a run. */
    public function test_each_module_carries_what_the_row_needs(): void
    {
        $subject = Roadmap::create([
            'title'         => 'Web Development',
            'description'   => 'A heading.',
            'review_status' => 'approved',
        ]);

        $this->roadmapWithEmptyModules($subject, 'Frontend', ['HTML']);

        $module = $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/modules-awaiting-material')
            ->assertOk()
            ->json('groups.0.modules.0');

        $this->assertSame(['version_node_id', 'title', 'difficulty', 'coming_soon'],
            array_keys($module));

        $this->assertSame('HTML', $module['title']);
        $this->assertIsInt($module['version_node_id']);
    }

    public function test_nothing_outstanding_is_an_empty_list_rather_than_an_object(): void
    {
        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson('/api/modules-awaiting-material')
            ->assertOk();

        $this->assertSame(0, $response->json('total'));
        $this->assertSame([], $response->json('groups'));
    }
}
