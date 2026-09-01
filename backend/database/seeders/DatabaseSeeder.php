<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Every seeder below is idempotent - running `php artisan db:seed`
     * repeatedly updates the existing rows instead of duplicating them.
     *
     * Order matters: each seeder resolves the records the next one needs.
     */
    public function run(): void
    {
        $this->call([
            UserSeeder::class,

            RoadmapSeeder::class,
            RoadmapVersionSeeder::class,

            NodeSeeder::class,
            EdgeSeeder::class,

            VersionNodeSeeder::class,
            VersionEdgeSeeder::class,

            TutorialSeeder::class,
            LearningContentSeeder::class,

            WorkshopSeeder::class,

            // Last: it reshapes what the seeders above produced into the
            // three-level tree, moving material rather than recreating it.
            RoadmapHierarchySeeder::class,
        ]);
    }
}
