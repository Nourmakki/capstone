<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\RoadmapVersion;

class RoadmapVersionSeeder extends Seeder
{
    public function run(): void
    {
        RoadmapVersion::create([
            'roadmap_id' => 1,
            'version_number' => 1,
            'status' => 'Published'
        ]);
    }
}