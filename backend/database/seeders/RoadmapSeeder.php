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
                [
                    'description' => $fixture['description'],
                    // Approved, or the seed produces a platform students
                    // cannot see. review_status defaults to pending, and a
                    // roadmap that is pending is withheld however much
                    // material hangs off it - so a fresh seed used to give a
                    // student an empty catalogue.
                    'review_status' => 'approved',
                ]
            );
        }
    }
}