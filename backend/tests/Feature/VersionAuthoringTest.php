<?php

namespace Tests\Feature;

use App\Models\ExternalResources;
use App\Models\Node;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

class VersionAuthoringTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    /** The prerequisites of every module in a version, keyed by node id. */
    private function prerequisites(RoadmapVersion $version): array
    {
        $map = [];

        foreach (VersionEdge::with('edge')->where('roadmap_version_id', $version->id)->get() as $versionEdge) {
            if ($versionEdge->edge) {
                $map[$versionEdge->edge->to_node_id][] = $versionEdge->edge->from_node_id;
            }
        }

        return $map;
    }

    // -----------------------------------------------------------------
    // Cloning a version
    // -----------------------------------------------------------------

    public function test_a_new_draft_carries_the_material_forward(): void
    {
        ['roadmap' => $roadmap, 'versionNodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        Tutorial::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'React Crash Course',
            'url'             => 'https://youtube.com/watch?v=x',
            'description'     => 'An introduction.',
            'source_name'     => 'Traversy',
            'review_status'   => 'approved',
        ]);

        $quiz = Quiz::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'Basics Quiz',
            'score'           => 0,
            'review_status'   => 'approved',
        ]);

        QuizQuestion::create([
            'quiz_id'  => $quiz->id,
            'question' => 'What is React for?',
            'type'     => 'multiple_choice',
            'options'  => ['UI', 'Databases'],
            'answer'   => 'UI',
        ]);

        Project::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'To-Do App',
            'description'     => 'Build one.',
            'difficulty'      => 'beginner',
            'review_status'   => 'approved',
        ]);

        ExternalResources::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'React docs',
            'url'             => 'https://react.dev',
            'type'            => 'documentation',
            'review_status'   => 'approved',
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$roadmap->id}/versions", ['title' => 'v2'])
            ->assertCreated();

        $draft = RoadmapVersion::where('roadmap_id', $roadmap->id)->orderByDesc('id')->first();
        $draftNodes = VersionNode::where('roadmap_version_id', $draft->id)->pluck('id');

        // Material hangs off version_node_id, so cloning nodes alone would
        // leave every module empty and publishing would strip the roadmap.
        $this->assertCount(2, $draftNodes);
        $this->assertSame(1, Tutorial::whereIn('version_node_id', $draftNodes)->count());
        $this->assertSame(1, Quiz::whereIn('version_node_id', $draftNodes)->count());
        $this->assertSame(1, Project::whereIn('version_node_id', $draftNodes)->count());
        $this->assertSame(1, ExternalResources::whereIn('version_node_id', $draftNodes)->count());

        $draftQuiz = Quiz::whereIn('version_node_id', $draftNodes)->first();

        $this->assertNotSame($quiz->id, $draftQuiz->id);
        $this->assertSame(1, QuizQuestion::where('quiz_id', $draftQuiz->id)->count());
    }

    public function test_editing_cloned_material_does_not_change_the_original(): void
    {
        ['roadmap' => $roadmap, 'versionNodes' => $nodes] = $this->makeRoadmap(['Basics']);

        $original = Tutorial::create([
            'version_node_id' => $nodes[0]->id,
            'title'           => 'React Crash Course',
            'url'             => 'https://youtube.com/watch?v=x',
            'description'     => 'An introduction.',
            'source_name'     => 'Traversy',
            'review_status'   => 'approved',
        ]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/roadmaps/{$roadmap->id}/versions", [])
            ->assertCreated();

        $draft = RoadmapVersion::where('roadmap_id', $roadmap->id)->orderByDesc('id')->first();
        $draftNodes = VersionNode::where('roadmap_version_id', $draft->id)->pluck('id');

        Tutorial::whereIn('version_node_id', $draftNodes)->first()->update(['title' => 'Edited in the draft']);

        $this->assertSame('React Crash Course', $original->fresh()->title);
    }

    // -----------------------------------------------------------------
    // Adding and editing modules
    // -----------------------------------------------------------------

    public function test_a_published_version_cannot_be_edited(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics'], 'Published');

        $module = VersionNode::where('roadmap_version_id', $version->id)->firstOrFail();

        // Students are following this one; editing it rewrites history.
        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/version-nodes/{$module->id}", [
                'title'       => 'Sneaky',
                'description' => 'Should not land.',
                'difficulty'  => 'beginner',
            ])
            ->assertStatus(422);

        $this->assertSame('Basics', $module->fresh()->node->title);
    }

    public function test_only_a_material_manager_may_edit_modules(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics'], 'Draft');

        $module = VersionNode::where('roadmap_version_id', $version->id)->firstOrFail();

        $payload = ['title' => 'X', 'description' => 'Y', 'difficulty' => 'beginner'];

        foreach (['makeStudent', 'makeProfessor', 'makeAdmin'] as $maker) {
            $this->actingAs($this->{$maker}(), 'sanctum')
                ->putJson("/api/version-nodes/{$module->id}", $payload)
                ->assertForbidden();
        }
    }

    // -----------------------------------------------------------------
    // Copy-on-write
    // -----------------------------------------------------------------

    public function test_renaming_a_module_in_a_draft_leaves_the_published_version_alone(): void
    {
        ['roadmap' => $roadmap, 'version' => $live, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmaps/{$roadmap->id}/versions", [])
            ->assertCreated();

        $draft = RoadmapVersion::where('roadmap_id', $roadmap->id)->orderByDesc('id')->first();

        // The clone shares Node rows with the published version, so editing
        // one would rename it under the students following the live roadmap.
        $draftNode = VersionNode::where('roadmap_version_id', $draft->id)
            ->where('node_id', $nodes[0]->id)
            ->first();

        $this->actingAs($manager, 'sanctum')
            ->putJson("/api/version-nodes/{$draftNode->id}", [
                'title'                 => 'React Fundamentals',
                'description'           => 'Renamed.',
                'difficulty'            => 'beginner',
                'prerequisite_node_ids' => [],
            ])
            ->assertOk();

        $this->assertSame('Basics', Node::find($nodes[0]->id)->title);

        // The draft's own edges must follow the copy, not the original.
        $draftPrereqs = $this->prerequisites($draft);
        $copyId = $draftNode->fresh()->node_id;

        $this->assertNotSame($nodes[0]->id, $copyId);
        $this->assertSame([$copyId], $draftPrereqs[$nodes[1]->id]);
    }

    // -----------------------------------------------------------------
    // Graph rules
    // -----------------------------------------------------------------

    public function test_a_prerequisite_loop_is_refused(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['A', 'B', 'C'], 'Draft');

        $first = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $nodes[0]->id)
            ->first();

        // A depends on C, which already depends on B, which depends on A.
        // Every module in that loop would be permanently locked.
        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/version-nodes/{$first->id}", [
                'title'                 => 'A',
                'description'           => 'About A.',
                'difficulty'            => 'beginner',
                'prerequisite_node_ids' => [$nodes[2]->id],
            ])
            ->assertStatus(422);

        $this->assertSame([], $this->prerequisites($version)[$nodes[0]->id] ?? []);
    }

    /**
     * The single-root rule, asked of the rules class directly.
     *
     * It used to be reached by adding a module; managers no longer add
     * modules, so it is now enforced where the AI commit path calls it.
     */
    public function test_a_second_starting_module_is_refused(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics', 'Components'], 'Draft');

        // A module with no prerequisites, beside one that already has none.
        $orphan = Node::create([
            'title' => 'Unattached', 'description' => 'No prerequisites.',
            'difficulty' => 'beginner', 'status' => 'approved',
        ]);

        VersionNode::create([
            'roadmap_version_id' => $version->id,
            'node_id'            => $orphan->id,
        ]);

        try {
            (new \App\Services\VersionGraphRules)->assertValid($version);
            $this->fail('Two starting modules were allowed.');
        } catch (\Illuminate\Validation\ValidationException $e) {
            $this->assertStringContainsString(
                'exactly one starting module',
                implode(' ', $e->errors()['prerequisite_node_ids'])
            );
        }
    }

    public function test_a_module_cannot_be_its_own_prerequisite(): void
    {
        ['version' => $version, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components'], 'Draft');

        $second = VersionNode::where('roadmap_version_id', $version->id)
            ->where('node_id', $nodes[1]->id)
            ->first();

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/version-nodes/{$second->id}", [
                'title'                 => 'Components',
                'description'           => 'About Components.',
                'difficulty'            => 'beginner',
                'prerequisite_node_ids' => [$nodes[1]->id],
            ])
            ->assertStatus(422);
    }

    // -----------------------------------------------------------------
    // Removing modules
    // -----------------------------------------------------------------

    public function test_a_module_holding_material_cannot_be_removed(): void
    {
        ['version' => $version, 'versionNodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components'], 'Draft');

        Tutorial::create([
            'version_node_id' => $nodes[1]->id,
            'title'           => 'Something',
            'url'             => 'https://example.com/x',
            'description'     => 'Attached.',
            'source_name'     => 'Someone',
            'review_status'   => 'approved',
        ]);

        // Deleting it would orphan the tutorial rather than warn about it.
        $this->actingAs($this->makeManager(), 'sanctum')
            ->deleteJson("/api/version-nodes/{$nodes[1]->id}")
            ->assertStatus(422)
            ->assertJsonPath('message', 'Remove the attached material first: 1 tutorials.');

        $this->assertNotNull(VersionNode::find($nodes[1]->id));
    }

    public function test_removing_the_last_module_drops_its_dangling_edge(): void
    {
        ['version' => $version, 'nodes' => $nodes, 'versionNodes' => $versionNodes] =
            $this->makeRoadmap(['Basics', 'Components'], 'Draft');

        $this->actingAs($this->makeManager(), 'sanctum')
            ->deleteJson("/api/version-nodes/{$versionNodes[1]->id}")
            ->assertOk();

        // An edge pointing at a module that no longer exists would make the
        // remaining graph unreadable.
        $this->assertSame(0, VersionEdge::where('roadmap_version_id', $version->id)->count());
        $this->assertNull(Node::find($nodes[1]->id));
        $this->assertNotNull(Node::find($nodes[0]->id));
    }

    public function test_a_node_shared_with_another_version_survives_removal(): void
    {
        ['roadmap' => $roadmap, 'nodes' => $nodes] = $this->makeRoadmap(['Basics', 'Components']);

        $manager = $this->makeManager();

        $this->actingAs($manager, 'sanctum')
            ->postJson("/api/roadmaps/{$roadmap->id}/versions", [])
            ->assertCreated();

        $draft = RoadmapVersion::where('roadmap_id', $roadmap->id)->orderByDesc('id')->first();

        $draftSecond = VersionNode::where('roadmap_version_id', $draft->id)
            ->where('node_id', $nodes[1]->id)
            ->first();

        $this->actingAs($manager, 'sanctum')
            ->deleteJson("/api/version-nodes/{$draftSecond->id}")
            ->assertOk();

        // The published version still uses it, so the node itself must stay.
        $this->assertNotNull(Node::find($nodes[1]->id));
    }
}