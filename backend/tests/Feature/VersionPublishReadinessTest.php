<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Quiz;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionNode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\BuildsPlatform;
use Tests\TestCase;

/**
 * A version may not go live until every module has something in all three
 * tabs. Both routes that publish are covered, because approving a version is
 * a second way to make it live and a rule enforced in only one of them is not
 * a rule.
 */
class VersionPublishReadinessTest extends TestCase
{
    use RefreshDatabase, BuildsPlatform;

    private function addTutorial(VersionNode $node, string $status = 'approved'): Tutorial
    {
        return Tutorial::create([
            'version_node_id' => $node->id,
            'title'           => 'Intro',
            'url'             => 'https://youtube.com/watch?v=x',
            'description'     => 'An introduction.',
            'source_name'     => 'Traversy',
            'review_status'   => $status,
        ]);
    }

    private function addQuiz(VersionNode $node, string $status = 'approved'): Quiz
    {
        return Quiz::create([
            'version_node_id' => $node->id,
            'title'           => 'Checkpoint',
            'review_status'   => $status,
        ]);
    }

    private function addProject(VersionNode $node, string $status = 'approved'): Project
    {
        return Project::create([
            'version_node_id' => $node->id,
            'title'           => 'Build something',
            'description'     => 'A small build.',
            'difficulty'      => 'beginner',
            'review_status'   => $status,
        ]);
    }

    /** Fills every tab on every module, so the version is fit to publish. */
    private function completeEvery(array $versionNodes): void
    {
        foreach ($versionNodes as $node) {
            $this->addTutorial($node);
            $this->addQuiz($node);
            $this->addProject($node);
        }
    }

    // -----------------------------------------------------------------
    // The happy path
    // -----------------------------------------------------------------

    public function test_a_version_with_all_three_on_every_module_publishes(): void
    {
        ['version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Basics', 'State'], 'Draft');

        $this->completeEvery($nodes);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmap-versions/{$version->id}/publish")
            ->assertOk();

        $this->assertSame('Published', $version->fresh()->status);
    }

    // -----------------------------------------------------------------
    // What is missing, and which tab it is
    // -----------------------------------------------------------------

    /**
     * Each tab on its own: leaving any one of the three empty is enough to
     * block the whole version.
     */
    #[DataProvider('missingTabProvider')]
    public function test_a_module_missing_one_tab_blocks_publishing(string $skip, string $word): void
    {
        ['version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Basics'], 'Draft');

        if ($skip !== 'tutorial') $this->addTutorial($nodes[0]);
        if ($skip !== 'quiz')     $this->addQuiz($nodes[0]);
        if ($skip !== 'project')  $this->addProject($nodes[0]);

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmap-versions/{$version->id}/publish")
            ->assertStatus(422);

        $this->assertStringContainsString($word, $response->json('message'));
        $this->assertSame('Draft', $version->fresh()->status);
    }

    public static function missingTabProvider(): array
    {
        return [
            'no tutorial' => ['tutorial', 'tutorial'],
            'no quiz'     => ['quiz', 'quiz'],
            'no project'  => ['project', 'project'],
        ];
    }

    /**
     * The common case: one module is finished and the next has not been
     * touched. The message has to name the module, or the manager is left
     * opening every one of them to find it.
     */
    public function test_the_message_names_the_module_that_is_short(): void
    {
        ['version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Basics', 'Advanced State'], 'Draft');

        $this->completeEvery([$nodes[0]]);
        $this->addTutorial($nodes[1]);

        $response = $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmap-versions/{$version->id}/publish")
            ->assertStatus(422);

        $message = $response->json('message');

        $this->assertStringContainsString('Advanced State', $message);
        $this->assertStringNotContainsString('Basics', $message);
    }

    /**
     * Pending material is invisible to students, so a module whose only quiz
     * is awaiting review would publish as an empty tab.
     */
    public function test_pending_material_does_not_count_as_filled_in(): void
    {
        ['version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Basics'], 'Draft');

        $this->addTutorial($nodes[0]);
        $this->addQuiz($nodes[0], 'pending');
        $this->addProject($nodes[0]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmap-versions/{$version->id}/publish")
            ->assertStatus(422);

        $this->assertSame('Draft', $version->fresh()->status);
    }

    public function test_an_empty_version_cannot_be_published(): void
    {
        ['version' => $version] = $this->makeRoadmap([], 'Draft');

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmap-versions/{$version->id}/publish")
            ->assertStatus(422);

        $this->assertSame('Draft', $version->fresh()->status);
    }

    // -----------------------------------------------------------------
    // The second way to go live
    // -----------------------------------------------------------------

    public function test_approving_a_version_is_gated_by_the_same_rule(): void
    {
        ['version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Basics'], 'Draft', 'pending');

        $this->addTutorial($nodes[0]);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/material/roadmap-versions/{$version->id}/approve")
            ->assertStatus(422);

        // Not approved either: a version that cannot go live should not be
        // left carrying an approval that says it can.
        $this->assertSame('Draft', $version->fresh()->status);
        $this->assertSame('pending', $version->fresh()->review_status);
    }

    public function test_approving_a_complete_version_still_publishes_it(): void
    {
        ['version' => $version, 'versionNodes' => $nodes] =
            $this->makeRoadmap(['Basics'], 'Draft', 'pending');

        $this->completeEvery($nodes);

        $this->actingAs($this->makeManager(), 'sanctum')
            ->postJson("/api/material/roadmap-versions/{$version->id}/approve")
            ->assertOk();

        $this->assertSame('Published', $version->fresh()->status);
        $this->assertSame('approved', $version->fresh()->review_status);
    }

    /**
     * The rule gates publishing, not what is already live. An older version
     * published before the rule existed keeps serving the students on it.
     */
    public function test_an_already_published_version_is_left_alone(): void
    {
        ['version' => $version] = $this->makeRoadmap(['Basics'], 'Published');

        $this->actingAs($this->makeManager(), 'sanctum')
            ->putJson("/api/roadmap-versions/{$version->id}/publish")
            ->assertStatus(409);

        $this->assertSame('Published', $version->fresh()->status);
    }
}
