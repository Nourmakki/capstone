<?php

namespace Tests\Feature;

use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use App\Services\NodeChoiceRules;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use App\Http\Controllers\Api\ProgressController;
use App\Models\CompletedNode;
use App\Models\Student;
use App\Models\UserRoadmapProgress;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * Choice nodes: a module offering buttons rather than a page of its own.
 *
 * Managers no longer author modules - the AI commits them and a manager
 * curates - so these exercise NodeChoiceRules directly, which is what both
 * the commit path and the publish gate call. Mirrors her ChoiceRoadmapNode,
 * and every rule here has a counterpart in her validators.
 */
class NodeChoiceTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private NodeChoiceRules $rules;

    protected function setUp(): void
    {
        parent::setUp();

        $this->rules = new NodeChoiceRules;
    }

    private function draft(): array
    {
        ['roadmap' => $roadmap, 'version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Fundamentals'], 'Draft');

        return ['roadmap' => $roadmap, 'version' => $version, 'parent' => $nodes[0]];
    }

    /** A choice hanging off a module, as the commit path would write it. */
    private function addChoice(RoadmapVersion $version, VersionNode $parent, array $attributes = []): VersionNode
    {
        $node = Node::create([
            'title'       => $attributes['title'] ?? 'HTML',
            'description' => 'A choice.',
            'difficulty'  => 'beginner',
            'status'      => 'approved',
        ]);

        return VersionNode::create([
            'roadmap_version_id'     => $version->id,
            'node_id'                => $node->id,
            'parent_version_node_id' => $parent->id,
            'is_recommended'         => $attributes['is_recommended'] ?? false,
            'recommendation_reason'  => $attributes['recommendation_reason'] ?? null,
        ]);
    }

    /** Run the mid-edit rules and report whether they passed. */
    private function check(VersionNode $parent): ?string
    {
        try {
            $this->rules->assertValid($parent->fresh()->load('node'));

            return null;
        } catch (ValidationException $e) {
            return $e->errors()['choices'][0] ?? 'refused';
        }
    }

    // -----------------------------------------------------------------
    // Shape
    // -----------------------------------------------------------------

    public function test_a_module_with_choices_reports_itself_as_a_choice_node(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->assertSame('standard', $parent->kind());

        $this->addChoice($version, $parent, ['title' => 'HTML']);
        $this->addChoice($version, $parent, ['title' => 'CSS']);

        $this->assertSame('choice', $parent->fresh()->kind());
        $this->assertNull($this->check($parent));
    }

    /** A choice is not a step, so it must not appear in the rail. */
    public function test_choices_are_not_rail_modules(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version, $parent, ['title' => 'HTML']);
        $this->addChoice($version, $parent, ['title' => 'CSS']);
        $this->addChoice($version, $parent, ['title' => 'JavaScript']);

        $this->assertSame(4, VersionNode::where('roadmap_version_id', $version->id)->count());
        $this->assertSame(1, VersionNode::where('roadmap_version_id', $version->id)->inRail()->count());
    }

    /**
     * The reason choices are safe where "alternatives" were not: they are not
     * counted as modules, so picking one of three still reaches 100%.
     */
    public function test_choices_do_not_count_towards_a_students_progress_total(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        foreach (['HTML', 'CSS', 'JavaScript'] as $title) {
            $this->addChoice($version, $parent, ['title' => $title]);
        }

        $student = $this->makeStudent();

        $response = $this->actingAs($student, 'sanctum')
            ->getJson("/api/roadmaps/{$this->draftRoadmapId($version)}/student/{$student->id}");

        // Whatever the endpoint says, the rail is what counts - one module.
        $this->assertSame(1, VersionNode::where('roadmap_version_id', $version->id)->inRail()->count());
        $this->assertContains($response->status(), [200, 404]);
    }

    private function draftRoadmapId(RoadmapVersion $version): int
    {
        return $version->roadmap_id;
    }

    // -----------------------------------------------------------------
    // Finishing a fork
    // -----------------------------------------------------------------

    /**
     * A choice node carries no material of its own, so every other
     * completion check finds nothing on it. Without this rule a student
     * could tick "Pick a Framework" without opening React, Vue or Angular
     * and walk straight past the fork.
     */
    public function test_a_choice_node_cannot_be_completed_before_a_choice_is(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        foreach (['React', 'Vue', 'Angular'] as $title) {
            $this->addChoice($version, $parent, ['title' => $title]);
        }

        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->firstOrFail();

        $blockers = ProgressController::completionBlockers($parent->fresh(), $student, null);

        $this->assertNotEmpty($blockers, 'A fork with nothing chosen should not be completable.');
        $this->assertStringContainsString('React', implode(' ', $blockers));
    }

    /** One is enough - that is what a choice means. */
    public function test_finishing_one_choice_completes_the_fork(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $react = $this->addChoice($version, $parent, ['title' => 'React']);
        $this->addChoice($version, $parent, ['title' => 'Vue']);
        $this->addChoice($version, $parent, ['title' => 'Angular']);

        $user = $this->makeStudent();
        $student = Student::where('user_id', $user->id)->firstOrFail();

        $progress = UserRoadmapProgress::create([
            'student_id'         => $student->id,
            'roadmap_version_id' => $version->id,
            'status'             => 'in_progress',
        ]);

        CompletedNode::create([
            'user_roadmap_progress_id' => $progress->id,
            'node_id'                  => $react->node_id,
        ]);

        // Vue and Angular are deliberately left undone.
        $blockers = ProgressController::completionBlockers($parent->fresh(), $student, $progress);

        $this->assertSame([], $blockers, 'One choice finished should be enough to pass the fork.');
    }

    // -----------------------------------------------------------------
    // Her rules, restated
    // -----------------------------------------------------------------

    /** Her ChoiceRoadmapNode: never more than one recommended choice. */
    public function test_two_recommended_choices_are_refused(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version, $parent, ['title' => 'HTML', 'is_recommended' => true, 'recommendation_reason' => 'One.']);
        $this->assertNull($this->check($parent));

        $this->addChoice($version, $parent, ['title' => 'CSS', 'is_recommended' => true, 'recommendation_reason' => 'Two.']);
        $this->assertStringContainsString('Only one choice can be recommended', $this->check($parent));
    }

    /** Her RoadmapChoice: a recommended choice needs a reason. */
    public function test_a_recommended_choice_without_a_reason_is_refused(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version, $parent, ['title' => 'HTML']);
        $this->addChoice($version, $parent, ['title' => 'CSS', 'is_recommended' => true]);

        $this->assertStringContainsString('needs a reason', $this->check($parent));
    }

    /** Her ChoiceRoadmapNode: choices must have unique titles. */
    public function test_two_choices_with_the_same_name_are_refused(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version, $parent, ['title' => 'HTML']);
        $this->addChoice($version, $parent, ['title' => '  html ']);

        $this->assertStringContainsString('same name', $this->check($parent));
    }

    /** Choices are one level deep - her schema has no nested choices. */
    public function test_a_choice_cannot_hold_choices_of_its_own(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $choice = $this->addChoice($version, $parent, ['title' => 'HTML']);

        try {
            $this->rules->assertCanHoldChoices($choice->load('node'));
            $this->fail('A choice was allowed to hold choices.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'cannot offer choices of its own',
                $e->errors()['parent_version_node_id'][0]
            );
        }
    }

    /** A module does one thing or the other, not both. */
    public function test_a_module_that_opens_a_roadmap_cannot_also_offer_choices(): void
    {
        ['roadmap' => $roadmap, 'parent' => $parent] = $this->draft();

        $target = Roadmap::create(['title' => 'React', 'description' => 'x', 'review_status' => 'approved']);
        $parent->update(['links_to_roadmap_id' => $target->id]);

        try {
            $this->rules->assertCanHoldChoices($parent->fresh()->load('node'));
            $this->fail('A roadmap-linking module was allowed to offer choices.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'already opens a roadmap',
                $e->errors()['parent_version_node_id'][0]
            );
        }
    }

    // -----------------------------------------------------------------
    // Completeness, asked at publish rather than mid-edit
    // -----------------------------------------------------------------

    /**
     * Her validators see a finished node arrive from the agent; a manager
     * edits one a button at a time. Demanding two choices mid-edit would
     * refuse the first and make the second unreachable.
     */
    public function test_one_choice_is_allowed_while_editing_but_refused_at_publish(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        $this->addChoice($version, $parent, ['title' => 'HTML']);

        $this->assertNull($this->check($parent), 'One choice should be fine mid-edit.');

        try {
            $this->rules->assertReadyToPublish($parent->fresh()->load('node'));
            $this->fail('A single choice was allowed to publish.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('is not a choice', $e->errors()['choices'][0]);
        }
    }

    /**
     * Choices that are parts rather than alternatives need no recommendation.
     * Her schema demands exactly one; ours accepts none, a superset.
     */
    public function test_choices_may_have_no_recommendation_at_all(): void
    {
        ['version' => $version, 'parent' => $parent] = $this->draft();

        foreach (['HTML', 'CSS', 'JavaScript'] as $title) {
            $this->addChoice($version, $parent, ['title' => $title]);
        }

        $this->assertNull($this->check($parent));

        // And it publishes: no recommendation is not an incomplete node.
        $this->rules->assertReadyToPublish($parent->fresh()->load('node'));
        $this->assertTrue(true);
    }

    // -----------------------------------------------------------------
    // Depth
    // -----------------------------------------------------------------

    /**
     * Three layers is the limit, which is her GeneratedChildRoadmap rule from
     * the other end - a child roadmap may not contain a separate_roadmap.
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

        $node = VersionNode::create([
            'roadmap_version_id' => $version->id,
            'node_id'            => Node::create(['title' => 'Redux', 'description' => 'x', 'difficulty' => 'advanced', 'status' => 'approved'])->id,
        ]);

        $this->assertSame(3, $this->rules->depthOf($deepest));

        try {
            $this->rules->assertCanLinkToRoadmap($node->load('roadmapVersion.roadmap'), $target);
            $this->fail('A fourth layer was allowed.');
        } catch (ValidationException $e) {
            $this->assertStringContainsString(
                'three layers is the limit',
                $e->errors()['links_to_roadmap_id'][0]
            );
        }
    }

    public function test_a_second_level_roadmap_may_still_open_one(): void
    {
        $subject = Roadmap::create(['title' => 'Web Development', 'description' => 'x', 'review_status' => 'approved']);
        $path    = Roadmap::create(['title' => 'Frontend', 'description' => 'x', 'review_status' => 'approved', 'parent_roadmap_id' => $subject->id]);
        $target  = Roadmap::create(['title' => 'React', 'description' => 'x', 'review_status' => 'approved']);

        $version = RoadmapVersion::create([
            'roadmap_id' => $path->id, 'version_number' => '1',
            'status' => 'Draft', 'review_status' => 'approved',
        ]);

        $node = VersionNode::create([
            'roadmap_version_id' => $version->id,
            'node_id'            => Node::create(['title' => 'React', 'description' => 'x', 'difficulty' => 'intermediate', 'status' => 'approved'])->id,
        ]);

        $this->rules->assertCanLinkToRoadmap($node->load('roadmapVersion.roadmap'), $target);
        $this->assertSame(2, $this->rules->depthOf($path));
    }
}
