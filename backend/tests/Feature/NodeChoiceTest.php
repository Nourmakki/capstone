<?php

namespace Tests\Feature;

use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Choice nodes: a module offering buttons rather than a page of its own.
 *
 * Fundamentals offers HTML, CSS and JavaScript; each is a page with its own
 * material. Mirrors the AI system's ChoiceRoadmapNode, and every rule here has
 * a counterpart in her validators.
 */
class NodeChoiceTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private function draft(): array
    {
        ['roadmap' => $roadmap, 'version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Fundamentals'], 'Draft');

        return ['roadmap' => $roadmap, 'version' => $version, 'parent' => $nodes[0]];
    }

    private function addChoice(int $versionId, int $parentId, array $overrides = [])
    {
        return $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$versionId}/modules", array_merge([
                'title'                  => 'HTML',
                'description'            => 'Structure of a page.',
                'difficulty'             => 'beginner',
                'parent_version_node_id' => $parentId,
            ], $overrides));
    }

    // -----------------------------------------------------------------
    // Creating choices
    // -----------------------------------------------------------------

    public function test_a_module_can_offer_choices_each_with_its_own_page(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, ['title' => 'HTML'])->assertCreated();
        $this->addChoice($version->id, $parent->id, [
            'title'                 => 'CSS',
            'is_recommended'        => true,
            'recommendation_reason' => 'Start here once the markup makes sense.',
        ])->assertCreated();

        $parent->refresh();

        $this->assertSame(2, $parent->choices()->count());
        $this->assertSame('choice', $parent->kind());

        // Each choice is a version_node, so material hangs off it exactly as
        // it does off a module - that is the whole reason for modelling it
        // this way rather than as a table of its own.
        $choice = $parent->choices()->first();
        $this->assertSame($version->id, $choice->roadmap_version_id);
    }

    /** A choice is not a step, so it must not appear in the rail. */
    public function test_a_choice_does_not_appear_as_a_module_in_the_rail(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, ['title' => 'HTML'])->assertCreated();
        $this->addChoice($version->id, $parent->id, [
            'title' => 'CSS', 'is_recommended' => true, 'recommendation_reason' => 'Because.',
        ])->assertCreated();

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->getJson("/api/roadmap-versions/{$version->id}/modules")
            ->assertOk();

        $titles = collect($response->json('modules'))->pluck('title');

        $this->assertSame(['Fundamentals'], $titles->all());
        $this->assertSame(
            ['CSS', 'HTML'],   // recommended first
            collect($response->json('modules.0.choices'))->pluck('title')->all()
        );
    }

    /**
     * The single-root rule counts prerequisites, and a choice has none by
     * design - so counting choices would report every one as a second place
     * to start and refuse the whole edit.
     */
    public function test_adding_a_choice_does_not_break_the_single_root_rule(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, ['title' => 'HTML'])->assertCreated();
        $this->addChoice($version->id, $parent->id, [
            'title' => 'CSS', 'is_recommended' => true, 'recommendation_reason' => 'Because.',
        ])->assertCreated();

        // A second rail module still has to declare its prerequisite.
        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/modules", [
                'title'                 => 'Layout',
                'description'           => 'Arranging a page.',
                'difficulty'            => 'beginner',
                'prerequisite_node_ids' => [$parent->node_id],
            ])
            ->assertCreated();
    }

    // -----------------------------------------------------------------
    // Her rules, restated
    // -----------------------------------------------------------------

    /** Her ChoiceRoadmapNode: exactly one recommended choice. */
    public function test_two_recommended_choices_are_refused(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, [
            'title' => 'HTML', 'is_recommended' => true, 'recommendation_reason' => 'One.',
        ])->assertCreated();

        $this->addChoice($version->id, $parent->id, [
            'title' => 'CSS', 'is_recommended' => true, 'recommendation_reason' => 'Two.',
        ])->assertStatus(422);

        $this->assertSame(1, $parent->choices()->count());
    }

    /** Her RoadmapChoice: a recommended choice needs a reason. */
    public function test_a_recommended_choice_without_a_reason_is_refused(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, ['title' => 'HTML'])->assertCreated();
        $this->addChoice($version->id, $parent->id, [
            'title' => 'CSS', 'is_recommended' => true,
        ])->assertStatus(422);
    }

    /** Her ChoiceRoadmapNode: choices must have unique titles. */
    public function test_two_choices_with_the_same_name_are_refused(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, [
            'title' => 'HTML', 'is_recommended' => true, 'recommendation_reason' => 'Because.',
        ])->assertCreated();

        $this->addChoice($version->id, $parent->id, ['title' => 'html'])->assertStatus(422);
    }

    /** Choices are one level deep - her schema has no nested choices. */
    public function test_a_choice_cannot_offer_choices_of_its_own(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, ['title' => 'HTML'])->assertCreated();
        $this->addChoice($version->id, $parent->id, [
            'title' => 'CSS', 'is_recommended' => true, 'recommendation_reason' => 'Because.',
        ])->assertCreated();

        $choice = $parent->choices()->first();

        $this->addChoice($version->id, $choice->id, ['title' => 'Flexbox'])->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // A module that opens a roadmap
    // -----------------------------------------------------------------

    public function test_a_module_can_open_a_roadmap_of_its_own(): void
    {
        ['roadmap' => $roadmap, 'version' => $version] = $this->draft();

        $react = Roadmap::create([
            'title'             => 'React',
            'description'       => 'Too big for one module.',
            'review_status'     => 'approved',
            'parent_roadmap_id' => $roadmap->id,
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/modules", [
                'title'                 => 'React',
                'description'           => 'Opens its own roadmap.',
                'difficulty'            => 'intermediate',
                'prerequisite_node_ids' => [],
                'links_to_roadmap_id'   => $react->id,
            ])
            ->assertStatus(422);   // two roots - the rail already has one

        $parent = VersionNode::where('roadmap_version_id', $version->id)->inRail()->first();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/modules", [
                'title'                 => 'React',
                'description'           => 'Opens its own roadmap.',
                'difficulty'            => 'intermediate',
                'prerequisite_node_ids' => [$parent->node_id],
                'links_to_roadmap_id'   => $react->id,
            ])
            ->assertCreated();

        $node = VersionNode::whereNotNull('links_to_roadmap_id')->first();

        $this->assertSame('roadmap', $node->kind());
        $this->assertSame($react->id, $node->links_to_roadmap_id);
    }

    /**
     * Three layers is the limit. A roadmap already three deep cannot hand off
     * to a fourth, which is her GeneratedChildRoadmap rule from the other end.
     */
    public function test_a_third_level_roadmap_cannot_open_another(): void
    {
        $subject = Roadmap::create(['title' => 'Web Development', 'description' => 'x', 'review_status' => 'approved']);
        $path    = Roadmap::create(['title' => 'Frontend', 'description' => 'x', 'review_status' => 'approved', 'parent_roadmap_id' => $subject->id]);
        $deepest = Roadmap::create(['title' => 'React', 'description' => 'x', 'review_status' => 'approved', 'parent_roadmap_id' => $path->id]);
        $target  = Roadmap::create(['title' => 'Redux', 'description' => 'x', 'review_status' => 'approved']);

        $version = RoadmapVersion::create([
            'roadmap_id' => $deepest->id, 'version_number' => '1',
            'status' => 'Draft', 'review_status' => 'approved',
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmap-versions/{$version->id}/modules", [
                'title'               => 'Redux',
                'description'         => 'One layer too far.',
                'difficulty'          => 'advanced',
                'links_to_roadmap_id' => $target->id,
            ])
            ->assertStatus(422);

        $this->assertSame(0, VersionNode::where('roadmap_version_id', $version->id)->count());
    }

    // -----------------------------------------------------------------
    // Progress
    // -----------------------------------------------------------------

    /**
     * The reason choices are safe where "alternatives" were not: they are not
     * counted as modules, so picking one of three still reaches 100%.
     */
    public function test_choices_do_not_count_towards_a_students_progress_total(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version->id, $parent->id, ['title' => 'HTML'])->assertCreated();
        $this->addChoice($version->id, $parent->id, [
            'title' => 'CSS', 'is_recommended' => true, 'recommendation_reason' => 'Because.',
        ])->assertCreated();
        $this->addChoice($version->id, $parent->id, ['title' => 'JavaScript'])->assertCreated();

        $this->assertSame(4, VersionNode::where('roadmap_version_id', $version->id)->count());
        $this->assertSame(1, VersionNode::where('roadmap_version_id', $version->id)->inRail()->count());
    }
}
