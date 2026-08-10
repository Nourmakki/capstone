<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {

        $this->call([
            StudentSeeder::class,

            RoadmapSeeder::class,
            RoadmapVersionSeeder::class,
            NodeSeeder::class,
            EdgeSeeder::class,
            VersionNodeSeeder::class,
            VersionEdgeSeeder::class,
        ]);
    }
}
