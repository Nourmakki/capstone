<?php

namespace Database\Seeders;

use App\Models\Node;
use Illuminate\Database\Seeder;

class NodeSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            foreach ($fixture['nodes'] as $node) {
                Node::updateOrCreate(
                    ['title' => $node['title']],
                    [
                        'description' => $node['description'],
                        'difficulty'  => $node['difficulty'],
                        'status'      => 'approved',
                    ]
                );
            }
        }
    }
}