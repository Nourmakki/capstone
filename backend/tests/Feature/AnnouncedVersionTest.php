<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * A version students may read but not enter.
 *
 * Committing an AI run writes a roadmap of modules with no material yet.
 * Announced, it is visible as coming soon - the shape can be read, every
 * module is locked - rather than invisible until somebody publishes it.
 *
 * The line that matters here is between announced and merely unpublished: a
 * draft a manager is halfway through editing must stay hidden, which is why
 * the flag is stored rather than inferred from "a draft with no material".
 */
class AnnouncedVersionTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    /** A roadmap whose only version is announced - a freshly built subject. */
    private function announcedRoadmap(string $title = 'Mobile'): array
    {
        ['roadmap' => $roadmap, 'version' => $version] = $this->makeRoadmap(
            ['Swift Basics', 'SwiftUI'],
            'Draft',
            'pending'
        );

        $roadmap->update(['title' => $title]);
        $version->update(['announced' => true]);

        return ['roadmap' => $roadmap->fresh(), 'version' => $version->fresh()];
    }

    // -----------------------------------------------------------------
    // What counts as announced
    // -----------------------------------------------------------------

    public function test_a_committed_draft_is_announced_but_a_published_one_is_not(): void
    {
        ['version' => $version] = $this->announcedRoadmap();

        $this->assertTrue($version->isAnnounced());

        // Publishing is what ends the preview. The flag is left alone; the
        // status is what decides, so a version is never both at once.
        $version->update(['status' => 'Published', 'review_status' => 'approved']);

        $this->assertFalse($version->fresh()->isAnnounced());
    }

    /**
     * The whole reason the flag is stored.
     *
     * A draft nobody announced is a draft somebody is working on, and it
     * looks identical to an announced one from its material alone.
     */
    public function test_a_plain_draft_stays_hidden_from_students(): void
    {
        ['roadmap' => $roadmap] = $this->makeRoadmap(['Basics'], 'Draft', 'pending');

        $titles = collect(
            $this->actingAs($this->makeStudent(), 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertNotContains($roadmap->parent->title, $titles);
    }

    // -----------------------------------------------------------------
    // What a student sees
    // -----------------------------------------------------------------

    /** A subject built by the AI shows up before anything is published. */
    public function test_an_announced_roadmap_reaches_the_student_catalogue(): void
    {
        ['roadmap' => $roadmap] = $this->announcedRoadmap();

        $listed = collect(
            $this->actingAs($this->makeStudent(), 'sanctum')->getJson('/api/roadmaps')->json('data')
        );

        // Listed under its subject, which is how a student reaches it.
        $subject = $listed->firstWhere('title', $roadmap->parent->title);

        $this->assertNotNull($subject, 'The subject should be listed.');
        $this->assertContains('Mobile', collect($subject['children'])->pluck('title')->all());
    }

    public function test_an_announced_version_is_offered_and_marked(): void
    {
        ['roadmap' => $roadmap, 'version' => $version] = $this->announcedRoadmap();
        $student = $this->makeStudent();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$roadmap->id}/student/{$student->id}")
            ->assertOk();

        $this->assertTrue($response->json('announced'));
        $this->assertSame($version->id, $response->json('version.id'));
        $this->assertTrue($response->json('version.announced'));

        // Nothing to continue, and nothing counted as done.
        $this->assertNull($response->json('current_node'));
        $this->assertSame([], $response->json('completed'));

        // The modules are still sent - the point is to read the shape.
        $this->assertCount(2, $response->json('nodes'));
    }

    /**
     * A preview is something you choose, not something you land in.
     *
     * A roadmap with a live version and an announced one beside it must open
     * on the live one, or a student following their course is dropped into a
     * page where nothing can be opened.
     */
    public function test_a_live_version_is_still_what_opens_by_default(): void
    {
        ['roadmap' => $roadmap, 'version' => $live] = $this->makeRoadmap(['Basics', 'Components']);

        $announced = RoadmapVersion::create([
            'roadmap_id'     => $roadmap->id,
            'version_number' => '2',
            'status'         => 'Draft',
            'review_status'  => 'pending',
            'announced'      => true,
        ]);

        $student = $this->makeStudent();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$roadmap->id}/student/{$student->id}")
            ->assertOk();

        $this->assertSame($live->id, $response->json('version.id'));
        $this->assertFalse($response->json('announced'));

        // But it is offered in the dropdown.
        $offered = collect($response->json('available_versions'))->pluck('id');
        $this->assertTrue($offered->contains($announced->id));

        // And picking it says so.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$roadmap->id}/student/{$student->id}?version={$announced->id}")
            ->assertOk()
            ->assertJsonPath('announced', true);
    }

    /** A pending roadmap with a live version stays hidden, as it always did. */
    public function test_announcing_does_not_open_the_door_to_unapproved_live_roadmaps(): void
    {
        ['roadmap' => $roadmap] = $this->makeRoadmap(['Basics']);

        $roadmap->update(['review_status' => 'pending']);

        $titles = collect(
            $this->actingAs($this->makeStudent(), 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertNotContains($roadmap->parent->title, $titles);
    }
}
