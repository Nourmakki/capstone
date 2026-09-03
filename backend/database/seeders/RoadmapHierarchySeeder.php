<?php

namespace Database\Seeders;

use App\Models\Edge;
use App\Models\Node;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Reshapes the flat seeded roadmaps into the three-level tree.
 *
 * The seeders before this one produce a subject with modules hanging directly
 * off it, which is what the platform looked like before roadmaps could nest.
 * This turns that into what it looks like now:
 *
 *   Web Development                a subject: paths, no modules of its own
 *     Frontend                     a path, with a rail
 *       Web Fundamentals           a module that opens a roadmap
 *         HTML -> CSS -> JavaScript    all three required, so: a roadmap
 *       Pick a Framework           a module offering choices
 *         React* | Vue | Angular       one is enough, so: choices
 *
 * Two shapes, and which one a node takes says something:
 *
 * - Everything under it mandatory -> it opens a roadmap, in order.
 * - One of them is enough -> choices on the node, each opening a roadmap.
 *
 * The subject's existing version is *moved* onto the roadmap where its
 * material now belongs rather than recreated, so the tutorials, quizzes and
 * projects the earlier seeders attached travel with it - they hang off
 * version_node_id, which does not change. Student progress moves with it too:
 * whoever finished React Basics finished it in React, which is where it lives.
 *
 * Idempotent: run it twice and the second run finds everything already in
 * place. That matters because it runs after seeders that are themselves
 * re-runnable.
 */
class RoadmapHierarchySeeder extends Seeder
{
    /**
     * The tree, as data.
     *
     * `inherits` is the path that adopts the subject's existing flat version -
     * the one the earlier seeders filled with material.
     */
    private function structure(): array
    {
        return [
            [
                'subject' => 'Web Development',
                'paths'   => ['Frontend', 'Backend', 'Full Stack'],
                'built'   => 'Frontend',
                'inherits' => 'React',
                'rail'    => [
                    [
                        'title' => 'Web Fundamentals',
                        'description' => 'HTML, CSS and JavaScript - all three, in order. Opens a roadmap of its own.',
                        'difficulty' => 'beginner', 'stage' => 1, 'stage_title' => 'Web Fundamentals',
                        'opens' => [
                            'title' => 'Web Fundamentals',
                            'description' => 'The three languages every page is built from, in the order they build on each other.',
                            'stage_title' => 'The Three Languages',
                            'modules' => [
                                ['HTML', 'Structure: elements, attributes, forms and semantics.', 'beginner'],
                                ['CSS', 'Presentation: the box model, flexbox, grid and responsive layout.', 'beginner'],
                                ['JavaScript', 'Behaviour: the language of the browser, and of everything after this.', 'beginner'],
                            ],
                        ],
                    ],
                    [
                        'title' => 'Pick a Framework',
                        'description' => 'One is enough. Each opens a roadmap of its own - choose the one you want to learn.',
                        'difficulty' => 'intermediate', 'stage' => 2, 'stage_title' => 'Frameworks and Tooling',
                        'after' => ['Web Fundamentals'],
                        'choices' => [
                            ['title' => 'React', 'recommended' => true,
                             'reason' => 'The most widely used of the three, and the easiest to find work with.'],
                            ['title' => 'Vue', 'opens' => [
                                'description' => 'A framework built around a gentle learning curve and single-file components.',
                                'modules' => [['Vue Basics', 'Templates, reactivity and single-file components.', 'intermediate']],
                            ]],
                            ['title' => 'Angular', 'opens' => [
                                'description' => 'A batteries-included framework, opinionated by design.',
                                'modules' => [['Angular Basics', 'Modules, components and dependency injection.', 'intermediate']],
                            ]],
                        ],
                    ],
                    [
                        'title' => 'TypeScript',
                        'description' => 'Types on top of JavaScript, and why large front ends want them.',
                        'difficulty' => 'intermediate', 'stage' => 2, 'stage_title' => 'Frameworks and Tooling',
                        'after' => ['Web Fundamentals'],
                    ],
                    [
                        'title' => 'Tailwind CSS',
                        'description' => 'Utility-first styling, and when it beats writing CSS by hand.',
                        'difficulty' => 'intermediate', 'stage' => 2, 'stage_title' => 'Frameworks and Tooling',
                        'after' => ['Web Fundamentals'],
                    ],
                ],
            ],

            [
                'subject' => 'AI / ML',
                'paths'   => ['Machine Learning', 'Deep Learning', 'Generative AI'],
                'built'   => 'Machine Learning',
                // Machine Learning keeps the subject's own version; only two of
                // its modules change shape.
                'reshape' => [
                    'Python for Data Science' => [
                        'opens' => [
                            'title' => 'Python for Data Science',
                            'description' => 'The three libraries every model starts with. All of them, in order.',
                            'stage_title' => 'The Toolkit',
                            'modules' => [
                                ['NumPy', 'Arrays, broadcasting and the numeric core everything else sits on.', 'beginner'],
                                ['pandas', 'DataFrames, indexing and getting real data into shape.', 'beginner'],
                                ['Matplotlib', 'Plotting, and seeing what the data is actually doing.', 'beginner'],
                            ],
                        ],
                        'description' => 'NumPy, pandas and Matplotlib - all three. Opens a roadmap of its own.',
                    ],
                    'Neural Networks' => [
                        'rename' => 'Pick a Deep Learning Framework',
                        'description' => 'One is enough. Each opens a roadmap of its own.',
                        'choices' => [
                            ['title' => 'PyTorch', 'recommended' => true,
                             'reason' => 'The one most course material and research code is written in.',
                             'opens' => [
                                'description' => 'The research-favoured framework: dynamic graphs and a Pythonic feel.',
                                'modules' => [['PyTorch Basics', 'Tensors, autograd and a first training loop.', 'intermediate']],
                             ]],
                            ['title' => 'TensorFlow', 'opens' => [
                                'description' => 'The production-favoured framework, with Keras on top for everyday work.',
                                'modules' => [['TensorFlow and Keras', 'Layers, models and the fit loop.', 'intermediate']],
                            ]],
                        ],
                    ],
                ],
            ],

            [
                'subject' => 'DevOps / Cloud',
                'paths'   => ['Platform Foundations', 'Cloud Infrastructure', 'Site Reliability'],
                'built'   => 'Platform Foundations',
                'reshape' => [
                    'Linux Fundamentals' => [
                        'opens' => [
                            'title' => 'Linux Fundamentals',
                            'description' => 'The shell, the filesystem and the permissions model - all of it required before anything else.',
                            'stage_title' => 'Core Linux',
                            'modules' => [
                                ['The Shell', 'Navigation, pipes and redirection.', 'beginner'],
                                ['Filesystem and Permissions', 'Paths, ownership and what the mode bits mean.', 'beginner'],
                                ['Processes and Services', 'Running things, and keeping them running.', 'intermediate'],
                            ],
                        ],
                        'description' => 'Shell, filesystem and processes - all three. Opens a roadmap of its own.',
                    ],
                    'CI/CD Pipelines' => [
                        'rename' => 'Pick a CI/CD Tool',
                        'description' => 'One is enough. Each opens a roadmap of its own.',
                        'choices' => [
                            ['title' => 'GitHub Actions', 'recommended' => true,
                             'reason' => 'Already where most repositories live, so there is nothing extra to host.',
                             'opens' => [
                                'description' => 'CI/CD wired into the repository, with YAML workflows.',
                                'modules' => [['Workflows and Runners', 'Triggers, jobs and where they execute.', 'intermediate']],
                             ]],
                            ['title' => 'GitLab CI', 'opens' => [
                                'description' => 'A single pipeline file, and a runner model built around it.',
                                'modules' => [['Pipelines and Stages', 'The .gitlab-ci.yml file and how stages run.', 'intermediate']],
                            ]],
                            ['title' => 'Jenkins', 'opens' => [
                                'description' => 'The long-standing self-hosted option, driven by plugins.',
                                'modules' => [['Jobs and Pipelines', 'Freestyle jobs, declarative pipelines and plugins.', 'intermediate']],
                            ]],
                        ],
                    ],
                ],
            ],

            [
                'subject' => 'Cybersecurity',
                'paths'   => ['Network Security', 'Application Security', 'Offensive Security'],
                'built'   => 'Network Security',
                'reshape' => [
                    'Web Application Security' => [
                        'rename' => 'Pick an Attack Surface',
                        'description' => 'One is enough to start. Each opens a roadmap of its own.',
                        'choices' => [
                            ['title' => 'Web Exploitation', 'recommended' => true,
                             'reason' => 'The surface most bugs and most jobs are found on.',
                             'opens' => [
                                'description' => 'Injection, broken access control and the rest, hands on.',
                                'modules' => [['Injection and XSS', 'How untrusted input becomes code, and how to stop it.', 'intermediate']],
                             ]],
                            ['title' => 'Binary Exploitation', 'opens' => [
                                'description' => 'Memory safety, and what happens when it is not there.',
                                'modules' => [['Buffer Overflows', 'The stack, the overflow, and the mitigations since.', 'advanced']],
                            ]],
                        ],
                    ],
                ],
            ],

            [
                'subject' => 'Mobile Development',
                'paths'   => ['Android Development', 'iOS Development', 'Cross-Platform Mobile Development'],
                'built'   => 'Android Development',
                'reshape' => [
                    'Building Interfaces' => [
                        'rename' => 'Pick a UI Toolkit',
                        'description' => 'One is enough. Each opens a roadmap of its own.',
                        'choices' => [
                            ['title' => 'Jetpack Compose', 'recommended' => true,
                             'reason' => 'The direction Android itself has moved to.',
                             'opens' => [
                                'description' => 'Declarative Android UI: composables, state and recomposition.',
                                'modules' => [['Composables and State', 'Building a screen that redraws itself when its data changes.', 'intermediate']],
                             ]],
                            ['title' => 'XML Layouts', 'opens' => [
                                'description' => 'The older view system, still under most existing apps.',
                                'modules' => [['Views and ViewGroups', 'Layouts, constraints and inflating a view.', 'intermediate']],
                            ]],
                        ],
                    ],
                ],
            ],

            [
                'subject' => 'Desktop Development',
                'paths'   => ['Cross-Platform Desktop', 'Native Desktop'],
                'built'   => 'Cross-Platform Desktop',
                'reshape' => [
                    'Working with the File System' => [
                        'opens' => [
                            'title' => 'Working with the File System',
                            'description' => 'Reading, writing and watching files - all three, in order.',
                            'stage_title' => 'Files and Storage',
                            'modules' => [
                                ['Reading and Writing', 'Paths, encodings and not losing a file half-written.', 'intermediate'],
                                ['Watching for Changes', 'Reacting when something edits a file underneath you.', 'intermediate'],
                                ['Local Storage', 'Settings, databases and where an app is allowed to put them.', 'intermediate'],
                            ],
                        ],
                        'description' => 'Reading, writing and watching files - all three. Opens a roadmap of its own.',
                    ],
                ],
            ],

            [
                'subject' => 'Game Development',
                'paths'   => ['Game Programming', 'Game Art and Design'],
                'built'   => 'Game Programming',
                'reshape' => [
                    'Graphics and Animation' => [
                        'rename' => 'Pick an Engine',
                        'description' => 'One is enough. Each opens a roadmap of its own.',
                        'choices' => [
                            ['title' => 'Godot', 'recommended' => true,
                             'reason' => 'Free, open source, and small enough to learn the whole of.',
                             'opens' => [
                                'description' => 'Nodes, scenes and GDScript.',
                                'modules' => [['Nodes and Scenes', 'How a Godot game is put together.', 'intermediate']],
                             ]],
                            ['title' => 'Unity', 'opens' => [
                                'description' => 'The engine most studio job adverts ask for.',
                                'modules' => [['GameObjects and Components', 'The component model, and C# scripting on top of it.', 'intermediate']],
                            ]],
                        ],
                    ],
                ],
            ],

            // Announced rather than built: the shape exists, the material does
            // not. Students see it in the catalogue tagged coming soon, can
            // read the modules, and cannot open any of them.
            [
                'subject'     => 'Data Engineering',
                'description' => 'Pipelines, warehouses, and moving data at scale.',
                'paths'       => ['Data Pipelines'],
                'built'       => 'Data Pipelines',
                'announced'   => true,
                'rail'        => [
                    [
                        'title' => 'How Data Moves',
                        'description' => 'Batch and streaming, and why a pipeline is not just a script.',
                        'difficulty' => 'beginner', 'stage' => 1, 'stage_title' => 'Foundations',
                    ],
                    [
                        'title' => 'Modelling a Warehouse',
                        'description' => 'Facts, dimensions, and designing for the questions people ask.',
                        'difficulty' => 'intermediate', 'stage' => 1, 'stage_title' => 'Foundations',
                        'after' => ['How Data Moves'],
                    ],
                    [
                        'title' => 'Orchestration',
                        'description' => 'Scheduling, retries and what happens when step four fails at 3am.',
                        'difficulty' => 'intermediate', 'stage' => 2, 'stage_title' => 'Running It',
                        'after' => ['Modelling a Warehouse'],
                    ],
                    [
                        'title' => 'Streaming at Scale',
                        'description' => 'Event logs, partitions and processing data that never stops arriving.',
                        'difficulty' => 'advanced', 'stage' => 2, 'stage_title' => 'Running It',
                        'after' => ['Orchestration'],
                    ],
                ],
            ],
        ];
    }

    public function run(): void
    {
        DB::transaction(function () {
            foreach ($this->structure() as $subjectSpec) {
                $this->buildSubject($subjectSpec);
            }
        });
    }

    private function buildSubject(array $spec): void
    {
        // Created when missing rather than skipped: Cybersecurity is not in
        // RoadmapFixtures, so requiring it to exist already left both it and
        // its path out of a fresh seed.
        $subject = Roadmap::firstOrCreate(
            ['title' => $spec['subject']],
            [
                'description'   => $spec['description'] ?? "{$spec['subject']} roadmaps.",
                'review_status' => 'approved',
            ]
        );

        $paths = [];

        foreach ($spec['paths'] as $title) {
            $paths[$title] = Roadmap::firstOrCreate(
                ['title' => $title],
                [
                    'description'       => "{$title}, under {$spec['subject']}.",
                    'review_status'     => 'approved',
                    'parent_roadmap_id' => $subject->id,
                ]
            );

            // An existing roadmap of that name still needs adopting.
            if ($paths[$title]->parent_roadmap_id === null) {
                $paths[$title]->update(['parent_roadmap_id' => $subject->id]);
            }
        }

        if (empty($spec['built'])) {
            return;
        }

        $built = $paths[$spec['built']];

        // A version still sitting on the subject means this has not run yet.
        // Anything already moved is left alone: on a second run the path's own
        // rail would otherwise look like the flat version and be moved again,
        // which is how running this twice used to rebuild Frontend inside
        // React.
        $flat = RoadmapVersion::where('roadmap_id', $subject->id)->orderBy('id')->first();

        if ($flat) {
            // Web Development's version carries React's material away with it;
            // the path then gets a rail of its own.
            $flat->update([
                'roadmap_id' => empty($spec['inherits'])
                    ? $built->id
                    : $this->childRoadmapNamed($built, $spec['inherits'])->id,
            ]);
        }

        $version = RoadmapVersion::where('roadmap_id', $built->id)->orderBy('id')->first()
            ?? $this->freshVersionFor($built, ! empty($spec['announced']));

        if (! empty($spec['rail'])) {
            $this->buildRail($built, $version, $spec['rail']);
        }

        foreach ($spec['reshape'] ?? [] as $moduleTitle => $change) {
            $this->reshapeModule($built, $version, $moduleTitle, $change);
        }
    }

    /** The roadmap a path's inherited material moves into - React, say. */
    private function childRoadmapNamed(Roadmap $parent, string $title): Roadmap
    {
        $roadmap = Roadmap::firstOrCreate(
            ['title' => $title],
            [
                'description'       => 'A framework large enough for a roadmap of its own.',
                'review_status'     => 'approved',
                'parent_roadmap_id' => $parent->id,
            ]
        );

        if ($roadmap->parent_roadmap_id !== $parent->id) {
            $roadmap->update(['parent_roadmap_id' => $parent->id]);
        }

        return $roadmap;
    }

    /**
     * A version for a path that has none.
     *
     * Announced means the shape exists but the material does not: it stays a
     * Draft, so students can read the modules and open none of them. Live is
     * the default, since every other path here arrives with material.
     */
    private function freshVersionFor(Roadmap $roadmap, bool $announced = false): RoadmapVersion
    {
        return RoadmapVersion::firstOrCreate(
            ['roadmap_id' => $roadmap->id, 'version_number' => '1'],
            $announced
                ? ['status' => 'Draft', 'review_status' => 'pending', 'announced' => true]
                : ['status' => 'Published', 'review_status' => 'approved']
        );
    }

    /** The path's own rail, built from the declared structure. */
    private function buildRail(Roadmap $path, RoadmapVersion $version, array $rail): void
    {
        if (VersionNode::where('roadmap_version_id', $version->id)->inRail()->exists()) {
            return;
        }

        $created = [];

        foreach ($rail as $spec) {
            $node = $this->makeNode($spec['title'], $spec['description'], $spec['difficulty']);

            $versionNode = VersionNode::create([
                'roadmap_version_id' => $version->id,
                'node_id'            => $node->id,
                'stage'              => $spec['stage'] ?? null,
                'stage_title'        => $spec['stage_title'] ?? null,
            ]);

            $created[$spec['title']] = $versionNode;

            if (! empty($spec['opens'])) {
                $versionNode->update([
                    'links_to_roadmap_id' => $this->buildChildRoadmap($path, $spec['opens'])->id,
                ]);
            }

            foreach ($spec['choices'] ?? [] as $choice) {
                $this->addChoice($path, $version, $versionNode, $choice);
            }
        }

        // Prerequisites, once every module exists to point at.
        foreach ($rail as $spec) {
            foreach ($spec['after'] ?? [] as $before) {
                $this->link($version, $created[$before]->node_id, $created[$spec['title']]->node_id);
            }
        }
    }

    /** Turn an existing module into one that opens a roadmap, or offers choices. */
    private function reshapeModule(Roadmap $path, RoadmapVersion $version, string $title, array $change): void
    {
        $versionNode = VersionNode::whereHas('node', fn ($q) => $q->where('title', $title))
            ->where('roadmap_version_id', $version->id)
            ->inRail()
            ->first();

        if (! $versionNode) {
            return;
        }

        if (! empty($change['rename'])) {
            Node::whereKey($versionNode->node_id)->update(['title' => $change['rename']]);
        }

        if (! empty($change['description'])) {
            Node::whereKey($versionNode->node_id)->update(['description' => $change['description']]);
        }

        if (! empty($change['opens']) && $versionNode->links_to_roadmap_id === null) {
            $versionNode->update([
                'links_to_roadmap_id' => $this->buildChildRoadmap($path, $change['opens'])->id,
            ]);
        }

        if (! empty($change['choices']) && ! $versionNode->choices()->exists()) {
            foreach ($change['choices'] as $choice) {
                $this->addChoice($path, $version, $versionNode, $choice);
            }
        }
    }

    /** A choice on a module, pointing at a roadmap of its own. */
    private function addChoice(Roadmap $path, RoadmapVersion $version, VersionNode $parent, array $choice): void
    {
        // A choice named after an existing roadmap opens that one - React
        // already exists, having inherited the seeded material.
        $target = Roadmap::where('title', $choice['title'])->first();

        if (! $target && ! empty($choice['opens'])) {
            $target = $this->buildChildRoadmap($path, $choice['opens'] + ['title' => $choice['title']]);
        }

        VersionNode::create([
            'roadmap_version_id'     => $version->id,
            'node_id'                => $this->makeNode(
                $choice['title'],
                "The {$choice['title']} roadmap.",
                'intermediate'
            )->id,
            'parent_version_node_id' => $parent->id,
            'links_to_roadmap_id'    => $target?->id,
            'is_recommended'         => $choice['recommended'] ?? false,
            'recommendation_reason'  => $choice['reason'] ?? null,
        ]);
    }

    /** A level-3 roadmap with its modules chained in order. */
    private function buildChildRoadmap(Roadmap $parent, array $spec): Roadmap
    {
        $roadmap = Roadmap::firstOrCreate(
            ['title' => $spec['title']],
            [
                'description'       => $spec['description'] ?? '',
                'review_status'     => 'approved',
                'parent_roadmap_id' => $parent->id,
            ]
        );

        if ($roadmap->parent_roadmap_id !== $parent->id) {
            $roadmap->update(['parent_roadmap_id' => $parent->id]);
        }

        $version = $this->freshVersionFor($roadmap);

        if (VersionNode::where('roadmap_version_id', $version->id)->exists()) {
            return $roadmap;
        }

        $previous = null;

        foreach ($spec['modules'] ?? [] as [$title, $description, $difficulty]) {
            $node = $this->makeNode($title, $description, $difficulty);

            VersionNode::create([
                'roadmap_version_id' => $version->id,
                'node_id'            => $node->id,
                'stage'              => 1,
                'stage_title'        => $spec['stage_title'] ?? 'Getting Started',
            ]);

            // Mandatory means ordered.
            if ($previous !== null) {
                $this->link($version, $previous, $node->id);
            }

            $previous = $node->id;
        }

        return $roadmap;
    }

    private function link(RoadmapVersion $version, int $from, int $to): void
    {
        $edge = Edge::firstOrCreate(['from_node_id' => $from, 'to_node_id' => $to]);

        VersionEdge::firstOrCreate([
            'roadmap_version_id' => $version->id,
            'edge_id'            => $edge->id,
        ]);
    }

    private function makeNode(string $title, string $description, string $difficulty): Node
    {
        return Node::create([
            'title'       => $title,
            'description' => $description,
            'difficulty'  => $difficulty,
            'status'      => 'approved',
        ]);
    }
}
