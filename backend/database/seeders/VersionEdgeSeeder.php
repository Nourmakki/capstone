<?php

namespace Database\Seeders;

use App\Models\Edge;
use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use Illuminate\Database\Seeder;

class VersionEdgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            $roadmap = Roadmap::where('title', $fixture['title'])->firstOrFail();

            $version = RoadmapVersion::where('roadmap_id', $roadmap->id)
                ->where('version_number', 1)
                ->firstOrFail();

            $nodeIds = Node::whereIn('title', array_column($fixture['nodes'], 'title'))
                ->pluck('id');

            $edges = Edge::whereIn('from_node_id', $nodeIds)
                ->whereIn('to_node_id', $nodeIds)
                ->get();

            foreach ($edges as $edge) {
                VersionEdge::updateOrCreate([
                    'roadmap_version_id' => $version->id,
                    'edge_id'            => $edge->id,
                ]);
            }
        }
    }
}