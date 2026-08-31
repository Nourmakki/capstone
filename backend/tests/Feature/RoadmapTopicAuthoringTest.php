<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * A manager creating a topic under a roadmap - Frontend under Web Development.
 *
 * The nine topics on the live site were seeded straight into the database, so
 * until now the hierarchy was only reachable by someone with a SQL client.
 * These cover the guards, because the tree being exactly two levels deep is an
 * assumption the student-facing pages rely on.
 */
class RoadmapTopicAuthoringTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private function parent(string $title = 'Web Development'): Roadmap
    {
        return Roadmap::create([
            'title'         => $title,
            'description'   => 'A broad area.',
            'review_status' => 'approved',
        ]);
    }

    public function test_a_manager_can_create_a_topic_under_a_roadmap(): void
    {
        $parent = $this->parent();

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmaps', [
                'title'             => 'Frontend',
                'description'       => 'Interfaces people actually use.',
                'parent_roadmap_id' => $parent->id,
            ])
            ->assertCreated();

        $this->assertSame($parent->id, $response->json('data.parent_roadmap_id'));
        $this->assertSame($parent->id, Roadmap::where('title', 'Frontend')->value('parent_roadmap_id'));
    }

    public function test_a_roadmap_created_without_a_parent_stays_top_level(): void
    {
        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmaps', [
                'title'       => 'Cybersecurity',
                'description' => 'Offensive and defensive security.',
            ])
            ->assertCreated();

        $this->assertNull(Roadmap::where('title', 'Cybersecurity')->value('parent_roadmap_id'));
    }

    /** An existing roadmap can be moved under a parent, and back out again. */
    public function test_an_existing_roadmap_can_be_moved_under_a_parent_and_back(): void
    {
        $parent = $this->parent();
        $child  = Roadmap::create(['title' => 'Backend', 'description' => 'The engine behind it.']);

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/roadmaps/{$child->id}", ['parent_roadmap_id' => $parent->id])
            ->assertOk();

        $this->assertSame($parent->id, $child->fresh()->parent_roadmap_id);

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/roadmaps/{$child->id}", ['parent_roadmap_id' => null])
            ->assertOk();

        $this->assertNull($child->fresh()->parent_roadmap_id);
    }

    // -----------------------------------------------------------------
    // The two-level guard
    // -----------------------------------------------------------------

    /**
     * Students see top-level roadmaps and one level of children, so a
     * grandchild would be content nobody could reach.
     */
    public function test_a_topic_cannot_hold_topics_of_its_own(): void
    {
        $parent = $this->parent();

        $topic = Roadmap::create([
            'title'             => 'Frontend',
            'description'       => 'Interfaces.',
            'parent_roadmap_id' => $parent->id,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmaps', [
                'title'             => 'React',
                'description'       => 'A framework.',
                'parent_roadmap_id' => $topic->id,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');

        $this->assertDatabaseMissing('roadmaps', ['title' => 'React']);
    }

    public function test_a_roadmap_that_already_has_topics_cannot_become_one(): void
    {
        $parent = $this->parent();
        $other  = $this->parent('AI / ML');

        Roadmap::create([
            'title'             => 'Frontend',
            'description'       => 'Interfaces.',
            'parent_roadmap_id' => $parent->id,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmaps/{$parent->id}", ['parent_roadmap_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');

        $this->assertNull($parent->fresh()->parent_roadmap_id);
    }

    public function test_a_roadmap_cannot_be_its_own_parent(): void
    {
        $roadmap = $this->parent();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmaps/{$roadmap->id}", ['parent_roadmap_id' => $roadmap->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');

        $this->assertNull($roadmap->fresh()->parent_roadmap_id);
    }

    public function test_an_unknown_parent_is_refused(): void
    {
        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmaps', [
                'title'             => 'Orphan',
                'description'       => 'Nowhere to live.',
                'parent_roadmap_id' => 999999,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');
    }

    /**
     * A roadmap a student cannot start is withheld rather than shown greyed
     * out: the card was an invitation to click and find nothing there.
     */
    public function test_a_roadmap_with_nothing_to_open_is_hidden_from_students(): void
    {
        $empty = $this->parent('Cybersecurity');

        // Its only topic has no published version, so there is still nothing
        // to start - a topic alone does not make a parent openable.
        Roadmap::create([
            'title'             => 'Network Security',
            'description'       => 'Nothing published yet.',
            'review_status'     => 'approved',
            'parent_roadmap_id' => $empty->id,
        ]);

        $student = $this->makeStudent();

        $titles = collect(
            $this->actingAs($student, 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertNotContains('Cybersecurity', $titles);

        // And hidden means hidden - not merely absent from the list.
        $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$empty->id}")
            ->assertStatus(404);
    }

    /** A manager still needs to reach the roadmap they are halfway through. */
    public function test_a_manager_still_sees_a_roadmap_with_nothing_to_open(): void
    {
        $empty = $this->parent('Cybersecurity');

        $manager = $this->makeManager();

        $titles = collect(
            $this->actingAs($manager, 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertContains('Cybersecurity', $titles);

        $this->actingAs($manager, 'sanctum')
            ->getJson("/api/roadmaps/{$empty->id}")
            ->assertOk();
    }

    public function test_only_a_material_manager_may_create_a_topic(): void
    {
        $parent = $this->parent();

        $this->actingAs($this->makeStudent(), 'sanctum')
            ->postJson('/api/roadmaps', [
                'title'             => 'Frontend',
                'description'       => 'Interfaces.',
                'parent_roadmap_id' => $parent->id,
            ])
            ->assertStatus(403);
    }

    /**
     * The point of the whole feature: a topic behaves like any other roadmap,
     * so the AI can fill it in through the same path - and it reaches students
     * on the same terms as everything else, which is to say only once approved.
     */
    public function test_a_new_topic_reaches_students_only_after_it_is_approved(): void
    {
        $parent  = $this->parent();
        $student = $this->makeStudent();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson('/api/roadmaps', [
                'title'             => 'Frontend',
                'description'       => 'Interfaces.',
                'parent_roadmap_id' => $parent->id,
            ])
            ->assertCreated();

        // Something to open, or the parent is withheld for a different reason
        // and this would not be testing approval at all.
        $topic = Roadmap::where('title', 'Frontend')->first();

        \App\Models\RoadmapVersion::create([
            'roadmap_id'     => $topic->id,
            'version_number' => '1',
            'status'         => 'Published',
            'review_status'  => 'approved',
        ]);

        $topicsFor = fn () => collect(
            $this->actingAs($student, 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->firstWhere('title', 'Web Development');

        // Pending, like any other new material: written but not yet live. The
        // parent has nothing else to offer, so it is withheld entirely rather
        // than shown as a chooser with no paths in it.
        $this->assertNull($topicsFor());

        $topic->update(['review_status' => 'approved']);

        $listed = $topicsFor();

        $this->assertNotNull($listed);
        $this->assertSame(['Frontend'], collect($listed['children'])->pluck('title')->all());

        // And it is a topic, not a peer: the student list stays top-level.
        $titles = collect(
            $this->actingAs($student, 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertContains('Web Development', $titles);
        $this->assertNotContains('Frontend', $titles);
    }
}
