<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Node;

class NodeSeeder extends Seeder
{
     public function run(): void
    {
        Node::updateOrCreate(
            ['id' => 1],
            [
                'title' => 'React Basics',
                'description' => 'Learn JSX and components.',
                'difficulty' => 'beginner',
                'status' => 'approved',
            ]
        );

        Node::updateOrCreate(
            ['id' => 2],
            [
                'title' => 'React Components',
                'description' => 'Reusable UI components.',
                'difficulty' => 'beginner',
                'status' => 'approved',
            ]
        );

        Node::updateOrCreate(
            ['id' => 3],
            [
                'title' => 'React State',
                'description' => 'Managing component state.',
                'difficulty' => 'beginner',
                'status' => 'approved',
            ]
        );

        Node::updateOrCreate(
            ['id' => 4],
            [
                'title' => 'React Hooks',
                'description' => 'Using useState and useEffect.',
                'difficulty' => 'intermediate',
                'status' => 'approved',
            ]
        );

        Node::updateOrCreate(
            ['id' => 5],
            [
                'title' => 'React Router',
                'description' => 'Navigation in React.',
                'difficulty' => 'intermediate',
                'status' => 'approved',
            ]
        );
    }
}