<?php

namespace Tests\Concerns;

use App\Models\Edge;
use App\Models\Node;
use App\Models\Professor;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Student;
use App\Models\User;
use App\Models\VersionEdge;
use App\Models\VersionNode;

/**
 * Setup helpers for feature tests.
 *
 * A roadmap is a graph across five tables, so building one inline in every
 * test would bury what each test is actually asserting.
 */
trait BuildsPlatform
{
    protected function makeStudent(array $attributes = []): User
    {
        $user = User::factory()->student()->create($attributes);

        Student::create(['user_id' => $user->id, 'level' => 'beginner']);

        return $user;
    }

    protected function makeProfessor(array $attributes = []): User
    {
        $user = User::factory()->professor()->create($attributes);

        Professor::create([
            'user_id'       => $user->id,
            'qualification' => 'PhD',
            'is_verified'   => true,
        ]);

        return $user;
    }

    protected function makeManager(array $attributes = []): User
    {
        return User::factory()->materialManager()->create($attributes);
    }

    protected function makeAdmin(array $attributes = []): User
    {
        return User::factory()->admin()->create($attributes);
    }

    /**
     * A roadmap with one version whose modules are chained in the order
     * given: the first has no prerequisites, each later one follows it.
     *
     * @param  array<int,string> $titles
     * @return array{roadmap: Roadmap, version: RoadmapVersion, nodes: array<int,Node>, versionNodes: array<int,VersionNode>}
     */
    protected function makeRoadmap(
        array $titles = ['Basics', 'Components', 'State'],
        string $status = 'Published',
        string $reviewStatus = 'approved'
    ): array {
        // Under a subject, because that is where a roadmap lives. A subject
        // is a heading - "Web Development" over Frontend and Backend - and it
        // holds no modules of its own, so a parentless roadmap with a version
        // is a shape the platform will not build.
        $subject = Roadmap::create([
            'title'         => 'Test Subject',
            'description'   => 'A heading built by a test.',
            'review_status' => $reviewStatus,
        ]);

        $roadmap = Roadmap::create([
            'title'             => 'Test Roadmap',
            'description'       => 'Built by a test.',
            'review_status'     => $reviewStatus,
            'parent_roadmap_id' => $subject->id,
        ]);

        $version = RoadmapVersion::create([
            'roadmap_id'     => $roadmap->id,
            'version_number' => '1',
            'status'         => $status,
            'review_status'  => $reviewStatus,
        ]);

        $nodes        = [];
        $versionNodes = [];
        $previous     = null;

        foreach ($titles as $title) {
            $node = Node::create([
                'title'       => $title,
                'description' => "About {$title}.",
                'difficulty'  => 'beginner',
                'status'      => 'approved',
            ]);

            $versionNodes[] = VersionNode::create([
                'roadmap_version_id' => $version->id,
                'node_id'            => $node->id,
            ]);

            if ($previous) {
                $edge = Edge::create([
                    'from_node_id' => $previous->id,
                    'to_node_id'   => $node->id,
                ]);

                VersionEdge::create([
                    'roadmap_version_id' => $version->id,
                    'edge_id'            => $edge->id,
                ]);
            }

            $nodes[]  = $node;
            $previous = $node;
        }

        return compact('roadmap', 'version', 'nodes', 'versionNodes');
    }
}