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

            // Once RoadmapHierarchySeeder has run, this roadmap is a subject:
            // its version was moved onto the path where the material belongs,
            // and a subject holds no version of its own.
            //
            // Without this the second `db:seed` found no version here and made
            // a new one, which the hierarchy seeder then moved onto the same
            // path - leaving every built path with a duplicate version, and a
            // second copy of every module inside it. The header on
            // DatabaseSeeder promised this file was re-runnable; it was not.
            if ($roadmap->children()->exists()) {
                continue;
            }

            RoadmapVersion::updateOrCreate(
                ['roadmap_id' => $roadmap->id, 'version_number' => 1],
                [
                    'status' => 'Published',
                    // Published is only half of live: a student reads the
                    // version that is published *and* approved, so without
                    // this the seeded roadmaps were published to nobody.
                    'review_status' => 'approved',
                ]
            );
        }
    }
}