<?php

namespace Database\Seeders;

use App\Models\Edge;
use App\Models\Node;
use Illuminate\Database\Seeder;

class EdgeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            $titles = array_column($fixture['nodes'], 'title');
            $ids    = Node::whereIn('title', $titles)->pluck('id', 'title');

            // Chain each roadmap's nodes in declared order.
            for ($i = 0; $i < count($titles) - 1; $i++) {
                $from = $ids[$titles[$i]] ?? null;
                $to   = $ids[$titles[$i + 1]] ?? null;

                if ($from && $to) {
                    Edge::updateOrCreate([
                        'from_node_id' => $from,
                        'to_node_id'   => $to,
                    ]);
                }
            }
        }
    }
}