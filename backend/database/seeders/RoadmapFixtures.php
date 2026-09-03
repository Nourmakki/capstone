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

            // -------------------------------------------------------------
            // The four subjects added alongside the original three. Each
            // gets its first module filled in - a tutorial, a quiz and a
            // project - so opening one leads somewhere rather than to three
            // empty tabs. RoadmapHierarchySeeder then reshapes each into a
            // subject with paths beneath it.
            // -------------------------------------------------------------

            [
                'title'       => 'Mobile Development',
                'description' => 'iOS, Android, and cross-platform apps.',
                'nodes'       => [
                    [
                        'title'       => 'Mobile Foundations',
                        'description' => 'How a phone app differs from a web page: lifecycles, screens and touch.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'Mobile Development Explained',
                                'url'         => 'https://www.youtube.com/watch?v=0-S5a0eXPoc',
                                'description' => 'What building for a phone actually involves.',
                                'source_name' => 'Programming with Mosh',
                            ],
                        ],
                        'resources'   => [
                            [
                                'title' => 'Android Developer Guides',
                                'url'   => 'https://developer.android.com/guide',
                                'type'  => 'documentation',
                            ],
                        ],
                        'quiz'        => [
                            'title'     => 'Mobile Foundations Quiz',
                            'questions' => [
                                [
                                    'question' => 'What is the activity lifecycle mainly used for?',
                                    'type'     => 'multiple_choice',
                                    'options'  => [
                                        'Styling the interface',
                                        'Reacting to a screen being shown, hidden or destroyed',
                                        'Storing passwords',
                                        'Compiling the app',
                                    ],
                                    'answer'   => 'Reacting to a screen being shown, hidden or destroyed',
                                ],
                                [
                                    'question' => 'A mobile app must handle being interrupted at any moment.',
                                    'type'     => 'true_false',
                                    'options'  => ['True', 'False'],
                                    'answer'   => 'True',
                                ],
                            ],
                        ],
                        'project'     => [
                            'title'       => 'Build a Single-Screen Notes App',
                            'description' => 'One screen, a list, and state that survives the screen being rotated.',
                            'difficulty'  => 'beginner',
                        ],
                    ],
                    [
                        'title'       => 'Building Interfaces',
                        'description' => 'Declarative UI: laying out a screen and reacting to state.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Data and Networking on Mobile',
                        'description' => 'Calling an API, caching what comes back, and working offline.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Shipping to the Stores',
                        'description' => 'Signing, review guidelines and getting an app published.',
                        'difficulty'  => 'advanced',
                        'tutorials'   => [],
                    ],
                ],
            ],

            [
                'title'       => 'Desktop Development',
                'description' => 'Native and cross-platform desktop applications.',
                'nodes'       => [
                    [
                        'title'       => 'Desktop App Foundations',
                        'description' => 'Windows, menus and the event loop every desktop app is built around.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'Build a Desktop App',
                                'url'         => 'https://www.youtube.com/watch?v=3yqDxhR2XxE',
                                'description' => 'From an empty window to a working application.',
                                'source_name' => 'Fireship',
                            ],
                        ],
                        'resources'   => [
                            [
                                'title' => 'Electron Documentation',
                                'url'   => 'https://www.electronjs.org/docs/latest',
                                'type'  => 'documentation',
                            ],
                        ],
                        'quiz'        => [
                            'title'     => 'Desktop Foundations Quiz',
                            'questions' => [
                                [
                                    'question' => 'What does an event loop do in a desktop application?',
                                    'type'     => 'multiple_choice',
                                    'options'  => [
                                        'Compiles the source code',
                                        'Waits for input and dispatches it to the interface',
                                        'Encrypts user files',
                                        'Installs the application',
                                    ],
                                    'answer'   => 'Waits for input and dispatches it to the interface',
                                ],
                                [
                                    'question' => 'A cross-platform desktop app trades some native feel for one codebase.',
                                    'type'     => 'true_false',
                                    'options'  => ['True', 'False'],
                                    'answer'   => 'True',
                                ],
                            ],
                        ],
                        'project'     => [
                            'title'       => 'Build a Desktop Markdown Editor',
                            'description' => 'A window, a file menu, and a live preview beside the text.',
                            'difficulty'  => 'beginner',
                        ],
                    ],
                    [
                        'title'       => 'Windowing and Layout',
                        'description' => 'Resizing, multiple windows, and layouts that survive both.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Working with the File System',
                        'description' => 'Reading, writing and watching files - the thing desktop apps do that web apps cannot.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Packaging and Auto-Update',
                        'description' => 'Installers, code signing, and shipping a second version.',
                        'difficulty'  => 'advanced',
                        'tutorials'   => [],
                    ],
                ],
            ],

            [
                'title'       => 'Cybersecurity',
                'description' => 'Offensive and defensive security.',
                'nodes'       => [
                    [
                        'title'       => 'Security Fundamentals',
                        'description' => 'Threats, the CIA triad, and thinking like an attacker.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'Cybersecurity Basics',
                                'url'         => 'https://www.youtube.com/watch?v=inWWhr5tnEA',
                                'description' => 'The vocabulary and the threat model everything else builds on.',
                                'source_name' => 'freeCodeCamp',
                            ],
                        ],
                        'resources'   => [
                            [
                                'title' => 'OWASP Top Ten',
                                'url'   => 'https://owasp.org/www-project-top-ten/',
                                'type'  => 'documentation',
                            ],
                        ],
                        'quiz'        => [
                            'title'     => 'Security Fundamentals Quiz',
                            'questions' => [
                                [
                                    'question' => 'What does the "I" in the CIA triad stand for?',
                                    'type'     => 'multiple_choice',
                                    'options'  => ['Isolation', 'Integrity', 'Identity', 'Inspection'],
                                    'answer'   => 'Integrity',
                                ],
                                [
                                    'question' => 'Encrypting data in transit protects it from being read on the wire.',
                                    'type'     => 'true_false',
                                    'options'  => ['True', 'False'],
                                    'answer'   => 'True',
                                ],
                            ],
                        ],
                        'project'     => [
                            'title'       => 'Audit a Small Web Application',
                            'description' => 'Find and write up three weaknesses against the OWASP Top Ten, with a fix for each.',
                            'difficulty'  => 'beginner',
                        ],
                    ],
                    [
                        'title'       => 'Networking for Security',
                        'description' => 'TCP/IP, ports and packet flow - what you have to see before you can defend it.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Web Application Security',
                        'description' => 'Injection, broken access control, and the rest of the Top Ten in practice.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Defence and Monitoring',
                        'description' => 'Logging, detection, and what to do once something has already happened.',
                        'difficulty'  => 'advanced',
                        'tutorials'   => [],
                    ],
                ],
            ],

            [
                'title'       => 'Game Development',
                'description' => 'Engines, gameplay programming, and graphics.',
                'nodes'       => [
                    [
                        'title'       => 'Game Development Foundations',
                        'description' => 'The game loop, frames and delta time - why games are written differently.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [
                            [
                                'title'       => 'How Game Engines Work',
                                'url'         => 'https://www.youtube.com/watch?v=q2LqOTIvDkQ',
                                'description' => 'What an engine does for you, and what it does not.',
                                'source_name' => 'Game Maker\'s Toolkit',
                            ],
                        ],
                        'resources'   => [
                            [
                                'title' => 'Godot Documentation',
                                'url'   => 'https://docs.godotengine.org/en/stable/',
                                'type'  => 'documentation',
                            ],
                        ],
                        'quiz'        => [
                            'title'     => 'Game Foundations Quiz',
                            'questions' => [
                                [
                                    'question' => 'Why is delta time used when moving objects?',
                                    'type'     => 'multiple_choice',
                                    'options'  => [
                                        'To make the game harder',
                                        'So movement is the same speed whatever the frame rate',
                                        'To reduce file size',
                                        'To load textures faster',
                                    ],
                                    'answer'   => 'So movement is the same speed whatever the frame rate',
                                ],
                                [
                                    'question' => 'The game loop runs once for every frame drawn.',
                                    'type'     => 'true_false',
                                    'options'  => ['True', 'False'],
                                    'answer'   => 'True',
                                ],
                            ],
                        ],
                        'project'     => [
                            'title'       => 'Build a 2D Endless Runner',
                            'description' => 'One character, one obstacle, a score, and a game-over screen.',
                            'difficulty'  => 'beginner',
                        ],
                    ],
                    [
                        'title'       => 'Gameplay Programming',
                        'description' => 'Input, movement, collision and state machines.',
                        'difficulty'  => 'beginner',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Graphics and Animation',
                        'description' => 'Sprites, shaders and making a scene readable at a glance.',
                        'difficulty'  => 'intermediate',
                        'tutorials'   => [],
                    ],
                    [
                        'title'       => 'Game Physics and AI',
                        'description' => 'Rigid bodies, pathfinding and enemies that appear to think.',
                        'difficulty'  => 'advanced',
                        'tutorials'   => [],
                    ],
                ],
            ],
        ];
    }
}