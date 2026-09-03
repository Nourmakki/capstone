<?php

namespace Database\Seeders;

use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\VersionNode;
use Illuminate\Database\Seeder;

class TutorialSeeder extends Seeder
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
                if (empty($nodeFixture['tutorials'])) {
                    continue;
                }

                $node = Node::where('title', $nodeFixture['title'])->first();

                $versionNode = $node
                    ? VersionNode::where('roadmap_version_id', $version->id)
                        ->where('node_id', $node->id)
                        ->first()
                    : null;

                if (! $versionNode) {
                    continue;
                }

                foreach ($nodeFixture['tutorials'] as $tutorial) {
                    Tutorial::updateOrCreate(
                        [
                            'version_node_id' => $versionNode->id,
                            'url'             => $tutorial['url'],
                        ],
                        [
                            'title'       => $tutorial['title'],
                            'description' => $tutorial['description'],
                            'source_name' => $tutorial['source_name'],
                            // Seeded material is material a manager already
                            // approved. Left pending it is invisible to
                            // students, and every module opens on empty tabs.
                            'review_status' => 'approved',
                        ]
                    );
                }
            }
        }
    }
}