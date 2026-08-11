<?php

namespace Database\Seeders;

use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use Illuminate\Database\Seeder;

class RoadmapVersionSeeder extends Seeder
{
    public function run(): void
    {
        foreach (RoadmapFixtures::all() as $fixture) {
            $roadmap = Roadmap::where('title', $fixture['title'])->firstOrFail();

            RoadmapVersion::updateOrCreate(
                ['roadmap_id' => $roadmap->id, 'version_number' => 1],
                ['status' => 'Published']
            );
        }
    }
}