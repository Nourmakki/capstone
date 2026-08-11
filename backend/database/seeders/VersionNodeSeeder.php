<?php

namespace Database\Seeders;

use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionNode;
use Illuminate\Database\Seeder;

class VersionNodeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            $roadmap = Roadmap::where('title', $fixture['title'])->firstOrFail();

            $version = RoadmapVersion::where('roadmap_id', $roadmap->id)
                ->where('version_number', 1)
                ->firstOrFail();

            $titles = array_column($fixture['nodes'], 'title');

            foreach (Node::whereIn('title', $titles)->get() as $node) {
                VersionNode::updateOrCreate([
                    'roadmap_version_id' => $version->id,
                    'node_id'            => $node->id,
                ]);
            }
        }
    }
}