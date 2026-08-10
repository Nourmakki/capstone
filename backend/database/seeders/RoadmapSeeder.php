<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Roadmap;

class RoadmapSeeder extends Seeder
{
    public function run(): void
    {
        Roadmap::create([
            'title' => 'Web Development',
            'description' => 'Complete roadmap for becoming a web developer.'
        ]);
    }
}