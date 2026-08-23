<?php

namespace Database\Seeders;

class RoadmapFixtures
{
    /**
     * All seeded roadmaps. Nodes are listed in learning order -
     * EdgeSeeder chains each roadmap's nodes in exactly this sequence.
     */
    public static function all(): array
    {
        return [
            [
                'title'       => 'Web Development',
                'description' => 'Frontend, backend, APIs, and full stack engineering.',
                'nodes'       => [
                    [
                        'title'       => 'React Basics',
                        'description' => 'Learn JSX and components.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'React Crash Course',
                                'url'         => 'https://www.youtube.com/watch?v=w7ejDZ8SWv8',
                                'description' => 'Complete introduction to React.',
                                'source_name' => 'Traversy Media',
                            ],
                        ],
                        'resources'   => [
                            [
                                'title' => 'React Official Documentation',
                                'url'   => 'https://react.dev/learn',
                                'type'  => 'documentation',
                            ],
                            [
                                'title' => 'Thinking in React',
                                'url'   => 'https://react.dev/learn/thinking-in-react',
                                'type'  => 'article',
                            ],
                        ],
                        'quiz'        => [
                            'title'     => 'React Basics Quiz',
                            'questions' => [
                                [
                                    'question' => 'What is React primarily used for?',
                                    'type'     => 'multiple_choice',
                                    'options'  => [
                                        'Database management',
                                        'Building user interfaces',
                                        'Operating systems',
                                        'Network security',
                                    ],
                                    'answer'   => 'Building user interfaces',
                                ],
                                [
                                    'question' => 'JSX lets you write HTML-like syntax inside JavaScript.',
                                    'type'     => 'true_false',
                                    'options'  => ['True', 'False'],
                                    'answer'   => 'True',
                                ],
                            ],
                        ],
                        'project'     => [
                            'title'       => 'Build a React To-Do App',
                            'description' => 'Create a functional to-do application using components, state, and event handling.',
                            'difficulty'  => 'beginner',
                        ],
                    ],
                    [
                        'title'       => 'React Components',
                        'description' => 'Reusable UI components.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'React Components Explained',
                                'url'         => 'https://www.youtube.com/watch?v=Y2hgEGPzTZY',
                                'description' => 'Understanding reusable components.',
                                'source_name' => 'Codevolution',
                            ],
                        ],
                        'resources'   => [
                            [
                                'title' => 'Components and Props',
                                'url'   => 'https://react.dev/learn/passing-props-to-a-component',
                                'type'  => 'documentation',
                            ],
                        ],
                    ],
                    [
                        'title'       => 'React State',
                        'description' => 'Managing component state.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'State and Props',
                                'url'         => 'https://www.youtube.com/watch?v=35lXWvCuM8o',
                                'description' => 'Learn state and props.',
                                'source_name' => 'Net Ninja',
                            ],
                        ],
                    ],
                    [
                        'title'       => 'React Hooks',
                        'description' => 'Using useState and useEffect.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [
                            [
                                'title'       => 'useState and useEffect',
                                'url'         => 'https://www.youtube.com/watch?v=O6P86uwfdR0',
                                'description' => 'The two hooks you will use most.',
                                'source_name' => 'Web Dev Simplified',
                            ],
                        ],
                    ],
                    [
                        'title'       => 'React Router',
                        'description' => 'Navigation in React.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                ],
            ],
            [
                'title'       => 'AI / ML',
                'description' => 'Machine learning, deep learning, LLMs, and AI systems.',
                'nodes'       => [
                    [
                        'title'       => 'Python for Data Science',
                        'description' => 'NumPy, pandas, and data handling.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'Python for Data Science',
                                'url'         => 'https://www.youtube.com/watch?v=LHBE6Q9XlzI',
                                'description' => 'Full data science foundations course.',
                                'source_name' => 'freeCodeCamp',
                            ],
                        ],
                    ],
                    [
                        'title'       => 'Supervised Learning',
                        'description' => 'Regression, classification, and model evaluation.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Neural Networks',
                        'description' => 'Layers, activations, and backpropagation.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Large Language Models',
                        'description' => 'Transformers, prompting, and fine-tuning.',
                        'difficulty'  => 'advanced',
                        'tutorials'   => [],
                    ],
                ],
            ],
            [
                'title'       => 'DevOps / Cloud',
                'description' => 'CI/CD, Docker, Kubernetes, and cloud infrastructure.',
                'nodes'       => [
                    [
                        'title'       => 'Linux Fundamentals',
                        'description' => 'Command line and system administration.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Git and Version Control',
                        'description' => 'Branching, merging, and collaboration.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Docker',
                        'description' => 'Containerising applications.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'CI/CD Pipelines',
                        'description' => 'Automating builds, tests, and deployment.',
                        'difficulty'  => 'advanced',
                        'tutorials'   => [],
                    ],
                ],
            ],
        ];
    }
}