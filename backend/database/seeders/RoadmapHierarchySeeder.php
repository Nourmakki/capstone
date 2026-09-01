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
                'subject'     => 'Cybersecurity',
                'description' => 'Offensive and defensive security.',
                'paths'       => ['Network Security'],
                // No built path: Cybersecurity has no material yet, so it stays
                // withheld from students until one of its paths is published.
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
            ?? $this->freshVersionFor($built);

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

    private function freshVersionFor(Roadmap $roadmap): RoadmapVersion
    {
        return RoadmapVersion::firstOrCreate(
            ['roadmap_id' => $roadmap->id, 'version_number' => '1'],
            ['status' => 'Published', 'review_status' => 'approved']
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
