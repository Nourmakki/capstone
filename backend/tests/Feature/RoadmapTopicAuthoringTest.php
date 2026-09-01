<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Services\NodeChoiceRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * The roadmap tree: who may shape it, and how deep it goes.
 *
 * Managers no longer create roadmaps. The AI builder proposes a subject and
 * its paths, and a manager approves, edits or rejects what it produced - so
 * the depth rules are enforced where that commit path and the edit route
 * reach them, not on a create route that no longer exists.
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

    // -----------------------------------------------------------------
    // Creation belongs to the AI
    // -----------------------------------------------------------------

    /**
     * The route is gone, not merely guarded: content originates with the AI
     * and a manager curates it.
     */
    public function test_no_one_can_create_a_roadmap_by_hand(): void
    {
        $payload = ['title' => 'Frontend', 'description' => 'Interfaces.'];

        foreach (['makeManager', 'makeAdmin', 'makeProfessor', 'makeStudent'] as $maker) {
            $response = $this->actingAs($this->{$maker}(), 'sanctum')
                ->postJson('/api/roadmaps', $payload);

            $this->assertContains(
                $response->status(),
                [404, 405, 403],
                "{$maker} reached a roadmap create route"
            );
        }

        $this->assertSame(0, Roadmap::count());
    }

    // -----------------------------------------------------------------
    // Editing the tree, which a manager may still do
    // -----------------------------------------------------------------

    public function test_a_manager_can_move_a_roadmap_under_another(): void
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

    public function test_only_a_material_manager_may_move_a_roadmap(): void
    {
        $parent = $this->parent();
        $child  = Roadmap::create(['title' => 'Backend', 'description' => 'x']);

        foreach (['makeStudent', 'makeProfessor', 'makeAdmin'] as $maker) {
            $this->actingAs($this->{$maker}(), 'sanctum')
                ->putJson("/api/roadmaps/{$child->id}", ['parent_roadmap_id' => $parent->id])
                ->assertForbidden();
        }

        $this->assertNull($child->fresh()->parent_roadmap_id);
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
        $child = Roadmap::create(['title' => 'Orphan', 'description' => 'x']);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmaps/{$child->id}", ['parent_roadmap_id' => 999999])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');
    }

    // -----------------------------------------------------------------
    // Three levels, and no more
    // -----------------------------------------------------------------

    /** A subject, its paths, and a path's own roadmap. React under Frontend. */
    public function test_three_levels_are_allowed(): void
    {
        $subject = $this->parent();
        $path    = Roadmap::create(['title' => 'Frontend', 'description' => 'x', 'parent_roadmap_id' => $subject->id]);
        $deepest = Roadmap::create(['title' => 'React', 'description' => 'x']);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmaps/{$deepest->id}", ['parent_roadmap_id' => $path->id])
            ->assertOk();

        $rules = new NodeChoiceRules;

        $this->assertSame(3, $rules->depthOf($deepest->fresh()));
    }

    /**
     * Three is the AI system's limit too - GeneratedChildRoadmap refuses a
     * separate_roadmap choice inside a child roadmap. A fourth level has
     * nowhere to be shown.
     */
    public function test_a_fourth_level_is_refused(): void
    {
        $subject = $this->parent();
        $path    = Roadmap::create(['title' => 'Frontend', 'description' => 'x', 'parent_roadmap_id' => $subject->id]);
        $deepest = Roadmap::create(['title' => 'React', 'description' => 'x', 'parent_roadmap_id' => $path->id]);
        $extra   = Roadmap::create(['title' => 'React Router', 'description' => 'x']);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmaps/{$extra->id}", ['parent_roadmap_id' => $deepest->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');

        $this->assertNull($extra->fresh()->parent_roadmap_id);
    }

    /** Moving a roadmap carries its topics, so the whole subtree has to fit. */
    public function test_a_move_that_would_push_topics_past_the_limit_is_refused(): void
    {
        $subject = $this->parent();
        $other   = $this->parent('AI / ML');

        $path = Roadmap::create(['title' => 'Frontend', 'description' => 'x', 'parent_roadmap_id' => $subject->id]);
        Roadmap::create(['title' => 'React', 'description' => 'x', 'parent_roadmap_id' => $path->id]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmaps/{$subject->id}", ['parent_roadmap_id' => $other->id])
            ->assertStatus(422)
            ->assertJsonValidationErrors('parent_roadmap_id');

        $this->assertNull($subject->fresh()->parent_roadmap_id);
    }

    // -----------------------------------------------------------------
    // What students see
    // -----------------------------------------------------------------

    /**
     * A professor marks work against modules three levels down, so a list
     * that stopped at the subject left them nothing to open at all.
     */
    public function test_a_professor_sees_every_level_of_the_tree(): void
    {
        $subject = $this->parent();

        $path = Roadmap::create([
            'title' => 'Frontend', 'description' => 'x',
            'review_status' => 'approved', 'parent_roadmap_id' => $subject->id,
        ]);

        RoadmapVersion::create([
            'roadmap_id' => $path->id, 'version_number' => '1',
            'status' => 'Published', 'review_status' => 'approved',
        ]);

        $deeper = Roadmap::create([
            'title' => 'React', 'description' => 'x',
            'review_status' => 'approved', 'parent_roadmap_id' => $path->id,
        ]);

        RoadmapVersion::create([
            'roadmap_id' => $deeper->id, 'version_number' => '1',
            'status' => 'Published', 'review_status' => 'approved',
        ]);

        $titles = fn ($user) => collect(
            $this->actingAs($user, 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $professor = $titles($this->makeProfessor());

        $this->assertContains('Web Development', $professor);
        $this->assertContains('Frontend', $professor);
        $this->assertContains('React', $professor);

        // A student is still walked down through the tree rather than shown
        // all of it at once.
        $student = $titles($this->makeStudent());

        $this->assertContains('Web Development', $student);
        $this->assertNotContains('Frontend', $student);
        $this->assertNotContains('React', $student);
    }

    /** Widening what is listed must not widen what may be seen. */
    public function test_a_professor_still_does_not_see_unapproved_roadmaps(): void
    {
        $subject = $this->parent();

        $pending = Roadmap::create([
            'title' => 'Frontend', 'description' => 'x',
            'review_status' => 'pending', 'parent_roadmap_id' => $subject->id,
        ]);

        RoadmapVersion::create([
            'roadmap_id' => $pending->id, 'version_number' => '1',
            'status' => 'Published', 'review_status' => 'approved',
        ]);

        $titles = collect(
            $this->actingAs($this->makeProfessor(), 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertNotContains('Frontend', $titles);

        // The manager, who has to approve it, does see it.
        $managerTitles = collect(
            $this->actingAs($this->makeManager(), 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->pluck('title');

        $this->assertContains('Frontend', $managerTitles);
    }

    /** A topic reaches students only once approved, like all material. */
    public function test_a_topic_reaches_students_only_after_it_is_approved(): void
    {
        $subject = $this->parent();

        $topic = Roadmap::create([
            'title'             => 'Frontend',
            'description'       => 'Interfaces.',
            'review_status'     => 'pending',
            'parent_roadmap_id' => $subject->id,
        ]);

        RoadmapVersion::create([
            'roadmap_id'     => $topic->id,
            'version_number' => '1',
            'status'         => 'Published',
            'review_status'  => 'approved',
        ]);

        $student = $this->makeStudent();

        $listed = fn () => collect(
            $this->actingAs($student, 'sanctum')->getJson('/api/roadmaps')->json('data')
        )->firstWhere('title', 'Web Development');

        // Pending, and the subject has nothing else to offer, so it is
        // withheld rather than shown as a chooser with no paths in it.
        $this->assertNull($listed());

        $topic->update(['review_status' => 'approved']);

        $this->assertSame(['Frontend'], collect($listed()['children'])->pluck('title')->all());
    }

    // -----------------------------------------------------------------
    // A subject is a title, not a roadmap
    // -----------------------------------------------------------------

    /**
     * "Web Development" is the heading over Frontend and Backend. Give it a
     * version of its own and it starts behaving like a fourth roadmap beside
     * the three it contains - offered in pickers, counted in totals, and
     * opening onto a rail where a choice of roadmap was meant.
     */
    public function test_a_subject_cannot_be_given_a_version(): void
    {
        $subject = $this->parent();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$subject->id}/versions", ['title' => 'v1'])
            ->assertStatus(422);

        $this->assertSame(0, RoadmapVersion::where('roadmap_id', $subject->id)->count());
    }

    /** The roadmaps under it take versions exactly as before. */
    public function test_a_roadmap_under_a_subject_still_takes_a_version(): void
    {
        $subject = $this->parent();

        $topic = Roadmap::create([
            'title'             => 'Frontend',
            'description'       => 'Interfaces.',
            'review_status'     => 'approved',
            'parent_roadmap_id' => $subject->id,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$topic->id}/versions", ['title' => 'v1'])
            ->assertCreated();

        $this->assertSame(1, RoadmapVersion::where('roadmap_id', $topic->id)->count());
    }

    /**
     * The admin's totals count the shelf, not the heading over it: four
     * subjects holding twenty-one roadmaps is not twenty-five roadmaps.
     */
    public function test_subjects_are_not_counted_among_the_roadmaps(): void
    {
        $subject = $this->parent();

        foreach (['Frontend', 'Backend'] as $title) {
            Roadmap::create([
                'title'             => $title,
                'description'       => 'A roadmap.',
                'review_status'     => 'approved',
                'parent_roadmap_id' => $subject->id,
            ]);
        }

        $stats = $this->actingAs($this->makeAdmin(), 'sanctum')
            ->getJson('/api/admin/dashboard-stats')
            ->assertOk()
            ->json();

        $this->assertSame(1, $stats['subjects']);
        $this->assertSame(2, $stats['roadmaps']);
    }
}
