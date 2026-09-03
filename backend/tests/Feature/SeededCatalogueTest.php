<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use Database\Seeders\DatabaseSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * What a freshly seeded platform actually shows.
 *
 * The seeders left every fixture roadmap and version at review_status
 * pending, so a fresh seed produced twenty-five roadmaps that no student and
 * no visitor could see - the catalogue was simply empty. It only looked
 * right on a database somebody had approved things in by hand.
 */
class SeededCatalogueTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_fresh_seed_is_visible_to_a_visitor_and_a_student(): void
    {
        $this->seed(DatabaseSeeder::class);

        // The landing page asks with no user at all.
        $guest = collect($this->getJson('/api/public/roadmaps')->assertOk()->json('data'))
            ->pluck('title');

        $this->assertNotEmpty($guest, 'A seeded platform must not show a visitor an empty catalogue.');

        $student = collect(
            $this->actingAs(\App\Models\User::where('role', 'Student')->firstOrFail(), 'sanctum')
                ->getJson('/api/roadmaps')->assertOk()->json('data')
        )->pluck('title');

        // A student sees what a visitor sees - the landing page is the same
        // catalogue behind a login.
        $this->assertSame($guest->sort()->values()->all(), $student->sort()->values()->all());
    }

    /** The four openable subjects, and the one that is only announced. */
    public function test_the_seeded_subjects_are_all_there(): void
    {
        $this->seed(DatabaseSeeder::class);

        $titles = collect($this->getJson('/api/public/roadmaps')->json('data'))->pluck('title');

        foreach ([
            'Web Development', 'AI / ML', 'DevOps / Cloud',
            'Mobile Development', 'Desktop Development', 'Cybersecurity',
            'Game Development', 'Data Engineering',
        ] as $subject) {
            $this->assertContains($subject, $titles->all(), "{$subject} should be in the catalogue.");
        }
    }

    public function test_the_new_subjects_open_onto_a_path_with_material(): void
    {
        $this->seed(DatabaseSeeder::class);

        foreach ([
            'Mobile Development'  => 'Android Development',
            'Desktop Development' => 'Cross-Platform Desktop',
            'Cybersecurity'       => 'Network Security',
            'Game Development'    => 'Game Programming',
        ] as $subjectTitle => $pathTitle) {
            $path = Roadmap::where('title', $pathTitle)->firstOrFail();

            $version = $path->versions()->orderBy('id')->firstOrFail();

            $this->assertSame('Published', $version->status, "{$pathTitle} should be live.");
            $this->assertSame('approved', $version->review_status);
            $this->assertFalse($version->isAnnounced(), "{$pathTitle} is openable, not coming soon.");

            $nodeIds = $version->versionNodes()->pluck('id');

            // Its first module is filled in, so opening one leads somewhere.
            $this->assertGreaterThan(0, \App\Models\Tutorial::whereIn('version_node_id', $nodeIds)->approved()->count());
            $this->assertGreaterThan(0, \App\Models\Quiz::whereIn('version_node_id', $nodeIds)->approved()->count());
            $this->assertGreaterThan(0, \App\Models\Project::whereIn('version_node_id', $nodeIds)->approved()->count());
        }
    }

    public function test_the_announced_subject_is_listed_but_locked(): void
    {
        $this->seed(DatabaseSeeder::class);

        $path = Roadmap::where('title', 'Data Pipelines')->firstOrFail();
        $version = $path->versions()->orderBy('id')->firstOrFail();

        $this->assertTrue($version->isAnnounced(), 'Data Pipelines is the coming-soon one.');
        $this->assertGreaterThan(0, $version->versionNodes()->count(), 'Its shape is readable.');

        $student = \App\Models\User::where('role', 'Student')->firstOrFail();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$path->id}/student/{$student->id}")
            ->assertOk();

        $this->assertTrue($response->json('announced'));
        $this->assertNull($response->json('current_node'), 'Nothing in it can be started.');
    }

    /**
     * The header on DatabaseSeeder promises this, and it was not true: the
     * hierarchy seeder moves a subject's version onto a path, and the second
     * run made a replacement that was moved onto the same path - so every
     * built path ended up with two versions and two copies of every module.
     */
    public function test_seeding_twice_changes_nothing(): void
    {
        $this->seed(DatabaseSeeder::class);

        $before = [
            'roadmaps' => Roadmap::count(),
            'versions' => RoadmapVersion::count(),
            'nodes'    => \App\Models\VersionNode::count(),
        ];

        $this->seed(DatabaseSeeder::class);

        $this->assertSame($before, [
            'roadmaps' => Roadmap::count(),
            'versions' => RoadmapVersion::count(),
            'nodes'    => \App\Models\VersionNode::count(),
        ]);

        $duplicated = Roadmap::withCount('versions')->get()
            ->filter(fn ($r) => $r->versions_count > 1)
            ->pluck('title')
            ->all();

        $this->assertSame([], $duplicated, 'No roadmap should end up with two versions.');
    }
}
