<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Node;
use App\Models\VersionNode;
use App\Models\Tutorial;

class TestingSeeder extends Seeder
{
    public function run(): void
    {
        // Roadmap

        $roadmap = Roadmap::create([
            'title' => 'Web Development',
            'description' => 'Learn Frontend and Backend Development'
        ]);

        // Version

        $version = RoadmapVersion::create([
            'roadmap_id' => $roadmap->id,
            'version_number' => 1,
            'status' => 'Published'
        ]);

        // Node

        $node = Node::create([
            'title' => 'React Basics',
            'description' => 'Introduction to React',
            'difficulty' => 'beginner',
            'status' => 'approved'
        ]);

        // Version Node

        $versionNode = VersionNode::create([
            'roadmap_version_id' => $version->id,
            'node_id' => $node->id
        ]);

        // Tutorials

        Tutorial::create([
            'version_node_id' => $versionNode->id,
            'title' => 'React Crash Course',
            'url' => 'https://www.youtube.com/watch?v=w7ejDZ8SWv8',
            'description' => 'React introduction',
            'source_name' => 'Traversy Media',
            'difficulty' => 'beginner'
        ]);

        Tutorial::create([
            'version_node_id' => $versionNode->id,
            'title' => 'React Components',
            'url' => 'https://www.youtube.com/watch?v=Y2hgEGPzTZY',
            'description' => 'Learn Components',
            'source_name' => 'Codevolution',
            'difficulty' => 'beginner'
        ]);

        Tutorial::create([
            'version_node_id' => $versionNode->id,
            'title' => 'State and Props',
            'url' => 'https://www.youtube.com/watch?v=IYvD9oBCuJI',
            'description' => 'State and Props explained',
            'source_name' => 'Programming with Mosh',
            'difficulty' => 'beginner'
        ]);
    }
}