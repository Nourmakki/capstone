<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\VersionEdge;

class VersionEdgeSeeder extends Seeder
{
    public function run(): void
    {
        VersionEdge::insert([
            [
                "roadmap_version_id" => 1,
                "edge_id" => 1,
            ],
            [
                "roadmap_version_id" => 1,
                "edge_id" => 2,
            ],
            [
                "roadmap_version_id" => 1,
                "edge_id" => 3,
            ],
            [
                "roadmap_version_id" => 1,
                "edge_id" => 4,
            ],
        ]);
    }
}