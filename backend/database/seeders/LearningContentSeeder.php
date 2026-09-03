<?php

namespace Database\Seeders;

use App\Models\ExternalResources;
use App\Models\Node;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use Illuminate\Database\Seeder;

class LearningContentSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            $roadmap = Roadmap::where('title', $fixture['title'])->firstOrFail();

            // Null once the hierarchy seeder has moved this roadmap's
            // version onto the path beneath it. Nothing left to do here.
            $version = RoadmapVersion::where('roadmap_id', $roadmap->id)
                ->where('version_number', '1')
                ->first();

            if (! $version) {
                continue;
            }

            foreach ($fixture['nodes'] as $nodeFixture) {
                $node = Node::where('title', $nodeFixture['title'])->first();

                $versionNode = $node
                    ? VersionNode::where('roadmap_version_id', $version->id)
                        ->where('node_id', $node->id)
                        ->first()
                    : null;

                if (! $versionNode) {
                    continue;
                }

                foreach ($nodeFixture['resources'] ?? [] as $resource) {
                    ExternalResources::updateOrCreate(
                        [
                            'version_node_id' => $versionNode->id,
                            'url'             => $resource['url'],
                        ],
                        [
                            'title' => $resource['title'],
                            'type'  => $resource['type'],
                            // Seeded material is already-approved material.
                            'review_status' => 'approved',
                        ]
                    );
                }

                if (! empty($nodeFixture['quiz'])) {
                    $quiz = Quiz::updateOrCreate(
                        [
                            'version_node_id' => $versionNode->id,
                            'title'           => $nodeFixture['quiz']['title'],
                        ],
                        ['score' => 0, 'review_status' => 'approved']
                    );

                    foreach ($nodeFixture['quiz']['questions'] as $question) {
                        QuizQuestion::updateOrCreate(
                            [
                                'quiz_id'  => $quiz->id,
                                'question' => $question['question'],
                            ],
                            [
                                'type'    => $question['type'],
                                'options' => $question['options'],
                                'answer'  => $question['answer'],
                            ]
                        );
                    }
                }

                if (! empty($nodeFixture['project'])) {
                    Project::updateOrCreate(
                        [
                            'version_node_id' => $versionNode->id,
                            'title'           => $nodeFixture['project']['title'],
                        ],
                        [
                            'description' => $nodeFixture['project']['description'],
                            'difficulty'  => $nodeFixture['project']['difficulty'],
                            'review_status' => 'approved',
                        ]
                    );
                }
            }
        }
    }
}