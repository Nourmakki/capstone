<?php

namespace Database\Seeders;

use App\Models\Roadmap;
use Illuminate\Database\Seeder;

class RoadmapSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            Roadmap::updateOrCreate(
                ['title' => $fixture['title']],
                ['description' => $fixture['description']]
            );
        }
    }
}