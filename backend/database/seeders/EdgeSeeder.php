<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Edge;

class EdgeSeeder extends Seeder
{
    public function run(): void
    {
        Edge::insert([
            [
                "id" => 1,
                "from_node_id" => 1,
                "to_node_id" => 2,
            ],
            [
                "id" => 2,
                "from_node_id" => 2,
                "to_node_id" => 3,
            ],
            [
                "id" => 3,
                "from_node_id" => 3,
                "to_node_id" => 4,
            ],
            [
                "id" => 4,
                "from_node_id" => 4,
                "to_node_id" => 5,
            ],
        ]);
    }
}