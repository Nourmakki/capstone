<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Tutorial;

class TutorialSeeder extends Seeder
{
    public function run(): void
    {
        Tutorial::create([
            'version_node_id' => 1,
            'title' => 'React Crash Course',
            'url' => 'https://www.youtube.com/watch?v=w7ejDZ8SWv8',
            'description' => 'Complete introduction to React.',
            'source_name' => 'Traversy Media',
            'difficulty' => 'beginner'
        ]);

        Tutorial::create([
            'version_node_id' => 1,
            'title' => 'React Components',
            'url' => 'https://www.youtube.com/watch?v=Y2hgEGPzTZY',
            'description' => 'Understanding components.',
            'source_name' => 'Codevolution',
            'difficulty' => 'beginner'
        ]);

        Tutorial::create([
            'version_node_id' => 1,
            'title' => 'State and Props',
            'url' => 'https://www.youtube.com/watch?v=35lXWvCuM8o',
            'description' => 'Learn State and Props.',
            'source_name' => 'Net Ninja',
            'difficulty' => 'beginner'
        ]);
    }
}