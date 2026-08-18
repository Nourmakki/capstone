<?php

namespace Database\Seeders;

use App\Models\Node;
use App\Models\VersionNode;
use App\Models\Workshop;
use Illuminate\Database\Seeder;

class WorkshopSeeder extends Seeder
{
    /**
     * Keyed by node title so workshops follow the topic a student is on.
     */
    private const WORKSHOPS = [
        'React Basics' => [
            [
                'title'            => 'React Fundamentals Bootcamp',
                'description'      => 'A hands-on day building your first React components.',
                'days_from_now'    => 7,
                'duration_minutes' => 240,
                'location'         => 'Beirut Digital District, Beirut',
                'country'          => 'Lebanon',
                'url'              => 'https://example.com/workshops/react-fundamentals',
            ],
            [
                'title'            => 'Remote: JSX Deep Dive',
                'description'      => 'Online session on JSX, props and rendering.',
                'days_from_now'    => 14,
                'duration_minutes' => 120,
                'location'         => 'Online',
                'country'          => null,
                'url'              => 'https://example.com/workshops/jsx-deep-dive',
            ],
        ],
        'React Hooks' => [
            [
                'title'            => 'Mastering React Hooks',
                'description'      => 'useState, useEffect and writing your own hooks.',
                'days_from_now'    => 10,
                'duration_minutes' => 180,
                'location'         => 'Tripoli Tech Hub, Tripoli',
                'country'          => 'Lebanon',
                'url'              => 'https://example.com/workshops/react-hooks',
            ],
        ],
        'Python for Data Science' => [
            [
                'title'            => 'Pandas in Practice',
                'description'      => 'Cleaning and analysing real datasets.',
                'days_from_now'    => 5,
                'duration_minutes' => 300,
                'location'         => 'AUB Campus, Beirut',
                'country'          => 'Lebanon',
                'url'              => 'https://example.com/workshops/pandas',
            ],
        ],
        'Docker' => [
            [
                'title'            => 'Containers from Scratch',
                'description'      => 'Build, run and ship containerised apps.',
                'days_from_now'    => 21,
                'duration_minutes' => 240,
                'location'         => 'Dubai Internet City',
                'country'          => 'United Arab Emirates',
                'url'              => 'https://example.com/workshops/containers',
            ],
        ],
    ];

    public function run(): void
    {
        foreach (self::WORKSHOPS as $nodeTitle => $workshops) {
            $node = Node::where('title', $nodeTitle)->first();

            if (! $node) {
                continue;
            }

            $versionNode = VersionNode::where('node_id', $node->id)->first();

            if (! $versionNode) {
                continue;
            }

            foreach ($workshops as $workshop) {
                Workshop::updateOrCreate(
                    [
                        'version_node_id' => $versionNode->id,
                        'title'           => $workshop['title'],
                    ],
                    [
                        'roadmap_version_id' => $versionNode->roadmap_version_id,
                        'description'        => $workshop['description'],
                        'scheduled_at'       => now()->addDays($workshop['days_from_now'])->setTime(10, 0),
                        'duration_minutes'   => $workshop['duration_minutes'],
                        'location'           => $workshop['location'],
                        'country'            => $workshop['country'],
                        'url'                => $workshop['url'],
                    ]
                );
            }
        }
    }
}