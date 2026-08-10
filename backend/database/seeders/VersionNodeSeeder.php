<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\VersionNode;

class VersionNodeSeeder extends Seeder
{
    public function run(): void
    {
        VersionNode::insert([
    [
        "id" => 1,
        "roadmap_version_id" => 1,
        "node_id" => 1,
    ],
    [
        "id" => 2,
        "roadmap_version_id" => 1,
        "node_id" => 2,
    ],
    [
        "id" => 3,
        "roadmap_version_id" => 1,
        "node_id" => 3,
    ],
    [
        "id" => 4,
        "roadmap_version_id" => 1,
        "node_id" => 4,
    ],
    [
        "id" => 5,
        "roadmap_version_id" => 1,
        "node_id" => 5,
    ],
]);
    }
}