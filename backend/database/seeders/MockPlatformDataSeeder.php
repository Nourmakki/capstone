<?php

namespace Database\Seeders;

use App\Models\CompletedNode;
use App\Models\Edge;
use App\Models\ExternalResources;
use App\Models\HumanReviewRequest;
use App\Models\Node;
use App\Models\Professor;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\QuizQuestion;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Student;
use App\Models\Submission;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TeamMessage;
use App\Models\ToDoTask;
use App\Models\Tutorial;
use App\Models\TutorialCompletion;
use App\Models\User;
use App\Models\UserRoadmapProgress;
use App\Models\VersionEdge;
use App\Models\VersionNode;
use App\Models\Workshop;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use RuntimeException;

/**
 * Fill the already-generated CS Mastery catalogue with deterministic demo data.
 *
 * Safety rules:
 * - never creates a new top-level roadmap;
 * - never changes the Database Engineer subtree;
 * - never overwrites real AI-generated learning material;
 * - creates approved mock material only when an approved item is missing;
 * - can be run repeatedly without duplicating its own records.
 */
class MockPlatformDataSeeder extends Seeder
{
    private const SEED_SOURCE = 'MockPlatformDataSeeder';

    public function run(): void
    {
        DB::transaction(function (): void {
            [$materialManager, $professor, $students] = $this->ensureDemoActors();

            $excludedRoadmapIds = $this->databaseEngineerSubtreeIds();

            $this->ensureGenerativeAiVersions($materialManager);
            $this->approveRoadmapsAndPublishLatestVersions(
                $materialManager,
                $excludedRoadmapIds,
            );
            $this->seedMissingLearningMaterial(
                $materialManager,
                $excludedRoadmapIds,
            );
            $this->seedWorkshops();
            $this->seedStudentActivity($students, $professor);
        });

        $this->command?->info(
            'Mock platform data added. Database Engineer was left unchanged, '
            .'and Generative AI Engineering now has three versions.'
        );
    }

    /**
     * @return array{0: User, 1: Professor, 2: Collection<int, Student>}
     */
    private function ensureDemoActors(): array
    {
        $materialManager = User::firstOrCreate(
            ['email' => 'material@capstone.test'],
            [
                'name' => 'Maya Material',
                'password' => Hash::make('password123'),
                'role' => 'MaterialManager',
                'location' => 'Beirut, Lebanon',
            ],
        );

        $professorUser = User::firstOrCreate(
            ['email' => 'professor@capstone.test'],
            [
                'name' => 'Paul Professor',
                'password' => Hash::make('password123'),
                'role' => 'Professor',
                'location' => 'Beirut, Lebanon',
            ],
        );

        $professor = Professor::firstOrCreate(
            ['user_id' => $professorUser->id],
            [
                'qualification' => 'PhD in Computer Science',
                'is_verified' => true,
            ],
        );

        $professorFixtures = [
            [
                'name' => 'Dr. Rana Haddad',
                'email' => 'rana.haddad@csmastery.test',
                'qualification' => 'PhD in Artificial Intelligence',
                'is_verified' => true,
            ],
            [
                'name' => 'Dr. Karim Nasser',
                'email' => 'karim.nasser@csmastery.test',
                'qualification' => 'PhD in Software Engineering',
                'is_verified' => true,
            ],
            [
                'name' => 'Dr. Layla Mansour',
                'email' => 'layla.mansour@csmastery.test',
                'qualification' => 'PhD in Cybersecurity',
                'is_verified' => false,
            ],
        ];

        foreach ($professorFixtures as $fixture) {
            $user = User::updateOrCreate(
                ['email' => $fixture['email']],
                [
                    'name' => $fixture['name'],
                    'password' => Hash::make('password123'),
                    'role' => $fixture['is_verified']
                        ? 'Professor'
                        : 'PendingProfessor',
                    'location' => 'Beirut, Lebanon',
                ],
            );

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            Professor::updateOrCreate(
                ['user_id' => $user->id],
                [
                    'qualification' => $fixture['qualification'],
                    'cv_path' => null,
                    'is_verified' => $fixture['is_verified'],
                ],
            );
        }

        $studentFixtures = [
            [
                'name' => 'Lina Haddad',
                'email' => 'demo.lina@capstone.test',
                'location' => 'Beirut, Lebanon',
                'level' => 'Intermediate',
            ],
            [
                'name' => 'Omar Khalil',
                'email' => 'demo.omar@capstone.test',
                'location' => 'Tripoli, Lebanon',
                'level' => 'Beginner',
            ],
            [
                'name' => 'Rana Saleh',
                'email' => 'demo.rana@capstone.test',
                'location' => 'Saida, Lebanon',
                'level' => 'Advanced',
            ],
            [
                'name' => 'Nour Makki',
                'email' => 'nourmakki@gmail.com',
                'location' => 'Beirut, Lebanon',
                'level' => 'Intermediate',
            ],
            [
                'name' => 'Fatima Zoghaib',
                'email' => 'fatimazoghaib@gmail.com',
                'location' => 'Beirut, Lebanon',
                'level' => 'Beginner',
            ],
            [
                'name' => 'Nour Shahine',
                'email' => 'nourshahine@gmail.com',
                'location' => 'Beirut, Lebanon',
                'level' => 'Intermediate',
            ],
        ];

        $students = collect($studentFixtures)->map(function (array $fixture): Student {
            $user = User::updateOrCreate(
                ['email' => $fixture['email']],
                [
                    'name' => $fixture['name'],
                    'password' => Hash::make('password123'),
                    'role' => 'Student',
                    'location' => $fixture['location'],
                ],
            );

            if ($user->email_verified_at === null) {
                $user->forceFill(['email_verified_at' => now()])->save();
            }

            return Student::updateOrCreate(
                ['user_id' => $user->id],
                ['level' => $fixture['level']],
            );
        })->values();

        return [$materialManager, $professor, $students];
    }

    /**
     * Return Database Engineer and all of its descendants.
     *
     * @return Collection<int, int>
     */
    private function databaseEngineerSubtreeIds(): Collection
    {
        $databaseEngineer = Roadmap::where('title', 'Database Engineer')
            ->whereNull('parent_roadmap_id')
            ->first();

        if (! $databaseEngineer) {
            throw new RuntimeException(
                'Database Engineer was not found; refusing to seed because its '
                .'coming-soon subtree cannot be protected.'
            );
        }

        $ids = collect([$databaseEngineer->id]);
        $frontier = collect([$databaseEngineer->id]);

        while ($frontier->isNotEmpty()) {
            $children = Roadmap::whereIn('parent_roadmap_id', $frontier)
                ->pluck('id');

            $newIds = $children->diff($ids)->values();

            if ($newIds->isEmpty()) {
                break;
            }

            $ids = $ids->merge($newIds)->unique()->values();
            $frontier = $newIds;
        }

        return $ids;
    }

    private function ensureGenerativeAiVersions(User $reviewer): void
    {
        $roadmap = Roadmap::where('title', 'Generative AI Engineering')
            ->firstOrFail();

        $versionOne = RoadmapVersion::where('roadmap_id', $roadmap->id)
            ->where('version_number', '1')
            ->firstOrFail();

        $versionOne->update([
            'title' => $versionOne->title ?: 'Foundations and RAG',
        ]);

        $versionTwo = RoadmapVersion::updateOrCreate(
            [
                'roadmap_id' => $roadmap->id,
                'version_number' => '2',
            ],
            [
                'title' => 'RAG and Agentic Systems',
                'status' => 'Archived',
                'announced' => false,
                ...$this->approvedFields($reviewer),
            ],
        );

        $this->cloneVersionNodes($versionOne, $versionTwo);
        $this->ensureVersionNode(
            $versionTwo,
            'AI Agents and Tool Use',
            'Build agentic AI systems that plan, call tools, maintain state, '
            .'and complete multi-step tasks safely.',
            'advanced',
        );
        $this->configureLinearSequence($versionTwo, [
            'Foundations of Large Language Models',
            'Prompt Engineering and Model Interaction',
            'Generative AI Orchestration Frameworks',
            'Retrieval-Augmented Generation (RAG)',
            'AI Agents and Tool Use',
            'Model Evaluation and Observability',
            'AI Security and Governance',
        ]);

        $versionThree = RoadmapVersion::updateOrCreate(
            [
                'roadmap_id' => $roadmap->id,
                'version_number' => '3',
            ],
            [
                'title' => 'Multimodal and Production Generative AI',
                'status' => 'Published',
                'announced' => false,
                ...$this->approvedFields($reviewer),
            ],
        );

        $this->cloneVersionNodes($versionTwo, $versionThree);
        $this->ensureVersionNode(
            $versionThree,
            'Multimodal Generative AI',
            'Design applications that combine text, images, audio, and other '
            .'modalities using modern generative models.',
            'advanced',
        );
        $this->ensureVersionNode(
            $versionThree,
            'LLMOps and Production Deployment',
            'Deploy, monitor, evaluate, secure, and continuously improve '
            .'generative AI applications in production.',
            'advanced',
        );
        $this->configureLinearSequence($versionThree, [
            'Foundations of Large Language Models',
            'Prompt Engineering and Model Interaction',
            'Multimodal Generative AI',
            'Generative AI Orchestration Frameworks',
            'Retrieval-Augmented Generation (RAG)',
            'AI Agents and Tool Use',
            'Model Evaluation and Observability',
            'LLMOps and Production Deployment',
            'AI Security and Governance',
        ]);
    }

    private function cloneVersionNodes(
        RoadmapVersion $source,
        RoadmapVersion $target,
    ): void {
        $sourceNodes = $source->versionNodes()->orderBy('id')->get();
        $targetBySourceVersionNode = [];

        foreach ($sourceNodes as $sourceNode) {
            $targetNode = VersionNode::updateOrCreate(
                [
                    'roadmap_version_id' => $target->id,
                    'node_id' => $sourceNode->node_id,
                ],
                [
                    'parent_version_node_id' => null,
                    'links_to_roadmap_id' => $sourceNode->links_to_roadmap_id,
                    'stage' => $sourceNode->stage,
                    'stage_title' => $sourceNode->stage_title,
                    'is_recommended' => $sourceNode->is_recommended,
                    'coming_soon' => $sourceNode->coming_soon,
                    'recommendation_reason' => $sourceNode->recommendation_reason,
                ],
            );

            $targetBySourceVersionNode[$sourceNode->id] = $targetNode;
        }

        foreach ($sourceNodes as $sourceNode) {
            if ($sourceNode->parent_version_node_id === null) {
                continue;
            }

            $targetBySourceVersionNode[$sourceNode->id]->update([
                'parent_version_node_id' => $targetBySourceVersionNode[$sourceNode->parent_version_node_id]->id,
            ]);
        }
    }

    private function ensureVersionNode(
        RoadmapVersion $version,
        string $title,
        string $description,
        string $difficulty,
    ): VersionNode {
        $node = Node::firstOrCreate(
            ['title' => $title],
            [
                'description' => $description,
                'difficulty' => $difficulty,
                'status' => 'approved',
            ],
        );

        return VersionNode::updateOrCreate(
            [
                'roadmap_version_id' => $version->id,
                'node_id' => $node->id,
            ],
            [
                'parent_version_node_id' => null,
                'links_to_roadmap_id' => null,
                'is_recommended' => false,
                'coming_soon' => true,
                'recommendation_reason' => null,
            ],
        );
    }

    /**
     * @param  array<int, string>  $titles
     */
    private function configureLinearSequence(
        RoadmapVersion $version,
        array $titles,
    ): void {
        $railNodes = $version->versionNodes()
            ->whereNull('parent_version_node_id')
            ->with('node')
            ->get()
            ->keyBy(fn (VersionNode $versionNode) => $versionNode->node->title);

        foreach ($titles as $index => $title) {
            $versionNode = $railNodes->get($title);

            if (! $versionNode) {
                throw new RuntimeException(
                    "Cannot build Generative AI version {$version->version_number}: "
                    ."missing node {$title}."
                );
            }

            $versionNode->update([
                'stage' => $index + 1,
                'stage_title' => null,
            ]);
        }

        VersionEdge::where('roadmap_version_id', $version->id)->delete();

        for ($index = 0; $index < count($titles) - 1; $index++) {
            $fromTitle = $titles[$index];
            $toTitle = $titles[$index + 1];
            $from = $railNodes->get($fromTitle);
            $to = $railNodes->get($toTitle);

            $edge = Edge::firstOrCreate([
                'from_node_id' => $from->node_id,
                'to_node_id' => $to->node_id,
            ]);

            VersionEdge::firstOrCreate([
                'roadmap_version_id' => $version->id,
                'edge_id' => $edge->id,
            ]);
        }
    }

    /**
     * @param  Collection<int, int>  $excludedRoadmapIds
     */
    private function approveRoadmapsAndPublishLatestVersions(
        User $reviewer,
        Collection $excludedRoadmapIds,
    ): void {
        Roadmap::whereNotIn('id', $excludedRoadmapIds)->update([
            ...$this->approvedFields($reviewer),
            'updated_at' => now(),
        ]);

        Roadmap::whereNotIn('id', $excludedRoadmapIds)
            ->whereHas('versions')
            ->with('versions')
            ->get()
            ->each(function (Roadmap $roadmap) use ($reviewer): void {
                $versions = $roadmap->versions->sort(
                    fn (RoadmapVersion $left, RoadmapVersion $right) => version_compare($left->version_number, $right->version_number),
                )->values();

                $latest = $versions->last();

                foreach ($versions as $version) {
                    $version->update([
                        'status' => $version->is($latest) ? 'Published' : 'Archived',
                        'announced' => false,
                        ...$this->approvedFields($reviewer),
                    ]);
                }
            });
    }

    /**
     * @param  Collection<int, int>  $excludedRoadmapIds
     */
    private function seedMissingLearningMaterial(
        User $reviewer,
        Collection $excludedRoadmapIds,
    ): void {
        VersionNode::query()
            ->whereHas(
                'roadmapVersion',
                fn ($query) => $query->whereNotIn('roadmap_id', $excludedRoadmapIds),
            )
            ->with(['node', 'choices'])
            ->orderBy('id')
            ->chunkById(100, function (Collection $versionNodes) use ($reviewer): void {
                foreach ($versionNodes as $versionNode) {
                    $opensAnotherRoadmap = $versionNode->links_to_roadmap_id !== null;
                    $offersChoices = $versionNode->choices->isNotEmpty();

                    if (! $opensAnotherRoadmap && ! $offersChoices) {
                        $this->seedTutorial($versionNode, $reviewer);
                        $this->seedQuiz($versionNode, $reviewer);
                        $this->seedProject($versionNode, $reviewer);
                        $this->seedResources($versionNode, $reviewer);
                    }

                    $versionNode->update(['coming_soon' => false]);
                }
            });
    }

    private function seedTutorial(VersionNode $versionNode, User $reviewer): void
    {
        if ($versionNode->tutorials()->approved()->exists()) {
            return;
        }

        $title = $versionNode->node->title;
        $url = 'https://www.youtube.com/results?search_query='
            .rawurlencode($title.' complete tutorial');

        Tutorial::updateOrCreate(
            [
                'version_node_id' => $versionNode->id,
                'url' => $url,
            ],
            [
                'title' => $title.': Complete Learning Guide',
                'description' => 'A structured tutorial covering the core ideas, '
                    .'practical techniques, and common implementation decisions in '
                    .$title.'.',
                'source_name' => 'CS Mastery Demo Library',
                ...$this->approvedContentFields($reviewer, $versionNode),
            ],
        );
    }

    private function seedQuiz(VersionNode $versionNode, User $reviewer): void
    {
        $approvedQuizzes = $versionNode->quizzes()->approved()->get();

        // AI-generated material remains the source of truth. Only quizzes
        // previously created by this demo seeder are expanded or rewritten.
        if ($approvedQuizzes->contains(fn (Quiz $quiz) => ! $this->isMockGenerated($quiz))) {
            return;
        }

        $title = $versionNode->node->title;

        $quiz = Quiz::updateOrCreate(
            [
                'version_node_id' => $versionNode->id,
                'title' => $title.' Knowledge Check',
            ],
            [
                'score' => 100,
                ...$this->approvedContentFields($reviewer, $versionNode),
            ],
        );

        $questions = [
            [
                'question' => "What is the main goal of studying {$title}?",
                'options' => [
                    'To understand and apply its core concepts',
                    'To memorize terminology without practice',
                    'To avoid testing implementations',
                    'To replace every related technology',
                ],
                'answer' => 'To understand and apply its core concepts',
            ],
            [
                'question' => "Which activity best demonstrates practical understanding of {$title}?",
                'options' => [
                    'Building and explaining a small working example',
                    'Reading only the lesson title',
                    'Copying code without running it',
                    'Skipping errors during development',
                ],
                'answer' => 'Building and explaining a small working example',
            ],
            [
                'question' => "What should a learner do after completing the {$title} tutorial?",
                'options' => [
                    'Apply the concepts and verify the result',
                    'Ignore the examples and exercises',
                    'Move forward without checking understanding',
                    'Remove all project documentation',
                ],
                'answer' => 'Apply the concepts and verify the result',
            ],
            [
                'question' => "Which practice supports maintainable work with {$title}?",
                'options' => [
                    'Use clear structure, testing, and documentation',
                    'Use unexplained names everywhere',
                    'Avoid reviewing the implementation',
                    'Keep known errors in production',
                ],
                'answer' => 'Use clear structure, testing, and documentation',
            ],
            [
                'question' => "What is the best first step when solving a new {$title} problem?",
                'options' => [
                    'Clarify the requirements and expected result',
                    'Start changing unrelated code immediately',
                    'Ignore the intended users',
                    'Choose tools before understanding the problem',
                ],
                'answer' => 'Clarify the requirements and expected result',
            ],
            [
                'question' => "How should a learner validate an implementation involving {$title}?",
                'options' => [
                    'Test expected behavior and important edge cases',
                    'Assume it works because it runs once',
                    'Remove validation to reduce code size',
                    'Check only the visual appearance',
                ],
                'answer' => 'Test expected behavior and important edge cases',
            ],
            [
                'question' => "Which documentation is most useful for a {$title} project?",
                'options' => [
                    'Setup steps, usage instructions, and design decisions',
                    'A title with no further explanation',
                    'Only a list of file names',
                    'Private notes that users cannot access',
                ],
                'answer' => 'Setup steps, usage instructions, and design decisions',
            ],
            [
                'question' => "When an error occurs while working with {$title}, what should happen?",
                'options' => [
                    'Handle it clearly and provide useful feedback',
                    'Hide every error without recording it',
                    'Delete the affected feature',
                    'Treat every error as a successful result',
                ],
                'answer' => 'Handle it clearly and provide useful feedback',
            ],
            [
                'question' => "What makes a {$title} solution easier to improve later?",
                'options' => [
                    'Modular structure and clear responsibilities',
                    'Duplicating the same logic everywhere',
                    'Using unexplained shortcuts',
                    'Combining every feature into one unit',
                ],
                'answer' => 'Modular structure and clear responsibilities',
            ],
            [
                'question' => "What is the strongest evidence that {$title} has been understood?",
                'options' => [
                    'A working project whose decisions can be explained',
                    'Recognizing the topic name',
                    'Watching a video without practicing',
                    'Copying an answer without reviewing it',
                ],
                'answer' => 'A working project whose decisions can be explained',
            ],
        ];

        foreach ($questions as $question) {
            QuizQuestion::updateOrCreate(
                [
                    'quiz_id' => $quiz->id,
                    'question' => $question['question'],
                ],
                [
                    'type' => 'multiple_choice',
                    'options' => $question['options'],
                    'answer' => $question['answer'],
                    'ai_metadata' => $this->mockMetadata($versionNode),
                ],
            );
        }

        // The mock contract is exactly ten questions. Removing only questions
        // belonging to this mock quiz cannot affect genuine AI material.
        $quiz->questions()
            ->whereNotIn('question', collect($questions)->pluck('question'))
            ->delete();
    }

    private function seedProject(VersionNode $versionNode, User $reviewer): void
    {
        $approvedProjects = $versionNode->projects()->approved()->get();

        if ($approvedProjects->contains(fn (Project $project) => ! $this->isMockGenerated($project))) {
            return;
        }

        $title = $versionNode->node->title;

        Project::updateOrCreate(
            [
                'version_node_id' => $versionNode->id,
                'title' => 'Build a Practical '.$title.' Project',
            ],
            [
                'description' => $this->projectDescription($title),
                'difficulty' => $versionNode->node->difficulty,
                'github_url' => null,
                ...$this->approvedContentFields($reviewer, $versionNode),
            ],
        );
    }

    private function projectDescription(string $title): string
    {
        return "Apply your knowledge of {$title} to build a focused, working application. "
            ."The project should demonstrate the topic's core concepts through a realistic use case and a clear implementation.\n\n"
            .'You are building this project as portfolio-quality evidence of your practical ability. '
            ."It must be understandable, testable, and complete enough for another developer to run and review.\n\n"
            ."What you will learn\n"
            ."Reinforce the main {$title} concepts, translate requirements into an implementation, test important behavior, and communicate technical decisions.\n\n"
            ."Tasks\n"
            ."- Define the problem, intended users, and expected outcome.\n"
            ."- Design a clear structure before implementing the main features.\n"
            ."- Apply the core {$title} concepts introduced in the learning material.\n"
            ."- Validate inputs and handle common failure cases clearly.\n"
            ."- Test the main behavior and at least two important edge cases.\n"
            ."- Keep the implementation readable and avoid duplicated logic.\n"
            ."- Document the setup, usage, and important design decisions.\n\n"
            ."Requirements\n"
            ."- A working implementation of the main use case\n"
            ."- Clear and maintainable project structure\n"
            ."- Appropriate validation and error handling\n"
            ."- Evidence of testing\n"
            ."- Setup and usage documentation\n\n"
            ."Constraints\n"
            ."- Use the concepts and technologies covered by this roadmap node.\n"
            ."- Do not submit copied code that you cannot explain.\n"
            ."- Do not include credentials, API keys, or private information.\n\n"
            ."Deliverables\n"
            ."- A public repository link or a ZIP file containing the source code.\n"
            ."- A README with installation and execution instructions.\n"
            ."- Screenshots, sample output, or tests proving the main requirements work.\n\n"
            ."Done when\n"
            ."- The main use case works from start to finish.\n"
            ."- The required {$title} concepts are clearly demonstrated.\n"
            ."- Invalid or unexpected input is handled without breaking the application.\n"
            ."- Another developer can run the project by following the README.\n"
            .'- The submitted evidence shows that the implementation was tested.';
    }

    private function isMockGenerated(mixed $model): bool
    {
        return data_get($model->ai_metadata, 'source') === self::SEED_SOURCE;
    }

    private function seedResources(VersionNode $versionNode, User $reviewer): void
    {
        if ($versionNode->externalResources()->approved()->count() >= 2) {
            return;
        }

        $title = $versionNode->node->title;
        $query = rawurlencode($title);

        $resources = [
            [
                'title' => $title.' Official Documentation Search',
                'url' => 'https://www.google.com/search?q='.$query.'+official+documentation',
                'type' => 'documentation',
            ],
            [
                'title' => $title.' Example Projects',
                'url' => 'https://github.com/search?q='.$query.'&type=repositories',
                'type' => 'article',
            ],
        ];

        foreach ($resources as $resource) {
            ExternalResources::updateOrCreate(
                [
                    'version_node_id' => $versionNode->id,
                    'url' => $resource['url'],
                ],
                [
                    'title' => $resource['title'],
                    'type' => $resource['type'],
                    ...$this->approvedContentFields($reviewer, $versionNode),
                ],
            );
        }
    }

    private function seedWorkshops(): void
    {
        $fixtures = [
            [
                'roadmap' => 'Frontend Development',
                'title' => 'Modern Frontend Architecture Workshop',
                'description' => 'A hands-on session covering component design, '
                    .'responsive interfaces, and maintainable frontend structure.',
                'organizer' => 'CS Mastery Community',
                'speaker' => 'Maya Hassan',
                'days' => 14,
                'duration' => 150,
                'location' => 'Beirut Digital District, Beirut',
                'country' => 'Lebanon',
                'cost_type' => 'free',
                'price' => null,
            ],
            [
                'roadmap' => 'Generative AI Engineering',
                'title' => 'Building Reliable RAG Applications',
                'description' => 'Build and evaluate a retrieval-augmented generation '
                    .'pipeline with embeddings, vector search, and grounded answers.',
                'organizer' => 'Lebanon AI Community',
                'speaker' => 'Karim Nasser',
                'days' => 24,
                'duration' => 180,
                'location' => 'Online',
                'country' => null,
                'cost_type' => 'free',
                'price' => null,
            ],
            [
                'roadmap' => 'Offensive Security',
                'title' => 'Ethical Hacking Lab Day',
                'description' => 'A controlled practical lab covering reconnaissance, '
                    .'web security testing, and responsible reporting.',
                'organizer' => 'Beirut Security Group',
                'speaker' => 'Rami Daher',
                'days' => 36,
                'duration' => 240,
                'location' => 'Hamra, Beirut',
                'country' => 'Lebanon',
                'cost_type' => 'paid',
                'price' => '20 USD',
            ],
            [
                'roadmap' => 'iOS Development',
                'title' => 'SwiftUI Application Development',
                'description' => 'Design and build a small SwiftUI application using '
                    .'modern state management and navigation patterns.',
                'organizer' => 'Mobile Developers Lebanon',
                'speaker' => 'Nour Saad',
                'days' => 48,
                'duration' => 120,
                'location' => 'Online',
                'country' => null,
                'cost_type' => 'free',
                'price' => null,
            ],
        ];

        foreach ($fixtures as $fixture) {
            $version = RoadmapVersion::query()
                ->whereHas('roadmap', fn ($query) => $query->where('title', $fixture['roadmap']))
                ->where('status', 'Published')
                ->orderByDesc('id')
                ->first();

            if (! $version) {
                continue;
            }

            Workshop::updateOrCreate(
                [
                    'roadmap_version_id' => $version->id,
                    'title' => $fixture['title'],
                ],
                [
                    'version_node_id' => $version->versionNodes()
                        ->inRail()
                        ->whereNull('links_to_roadmap_id')
                        ->whereDoesntHave('choices')
                        ->orderByRaw('COALESCE(stage, 999), id')
                        ->value('id'),
                    'description' => $fixture['description'],
                    'organizer' => $fixture['organizer'],
                    'speaker' => $fixture['speaker'],
                    'scheduled_at' => now()->addDays($fixture['days'])->setTime(10, 0),
                    'duration_minutes' => $fixture['duration'],
                    'location' => $fixture['location'],
                    'country' => $fixture['country'],
                    'url' => null,
                    'cost_type' => $fixture['cost_type'],
                    'price' => $fixture['price'],
                    'ai_metadata' => [
                        'source' => self::SEED_SOURCE,
                        'mock' => true,
                    ],
                ],
            );
        }
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    private function seedStudentActivity(Collection $students, Professor $professor): void
    {
        $studyPlans = [
            'demo.lina@capstone.test' => [
                ['roadmap' => 'Generative AI Engineering', 'version' => '3', 'completed' => 9, 'start_days_ago' => 29],
                ['roadmap' => 'Frontend Development', 'completed' => 4, 'start_days_ago' => 20],
                ['roadmap' => 'Machine Learning Engineering', 'completed' => 3, 'start_days_ago' => 12],
            ],
            'demo.omar@capstone.test' => [
                ['roadmap' => 'Frontend Development', 'completed' => 7, 'start_days_ago' => 28],
                ['roadmap' => 'Backend Development', 'completed' => 4, 'start_days_ago' => 19],
                ['roadmap' => 'Full-Stack Development', 'completed' => 3, 'start_days_ago' => 11],
            ],
            'demo.rana@capstone.test' => [
                ['roadmap' => 'iOS Development', 'completed' => 6, 'start_days_ago' => 27],
                ['roadmap' => 'Cross-Platform Mobile Development', 'completed' => 4, 'start_days_ago' => 18],
                ['roadmap' => 'Flutter', 'completed' => 3, 'start_days_ago' => 10],
            ],
            'nourmakki@gmail.com' => [
                ['roadmap' => 'Generative AI Engineering', 'version' => '3', 'completed' => 7, 'start_days_ago' => 29],
                ['roadmap' => 'Machine Learning Engineering', 'completed' => 5, 'start_days_ago' => 21],
                ['roadmap' => 'MLOps Engineering', 'completed' => 3, 'start_days_ago' => 12],
            ],
            'fatimazoghaib@gmail.com' => [
                ['roadmap' => 'Frontend Development', 'completed' => 6, 'start_days_ago' => 28],
                ['roadmap' => 'Backend Development', 'completed' => 3, 'start_days_ago' => 18],
                ['roadmap' => 'Full-Stack Development', 'completed' => 2, 'start_days_ago' => 9],
            ],
            'nourshahine@gmail.com' => [
                ['roadmap' => 'Offensive Security', 'completed' => 7, 'start_days_ago' => 29],
                ['roadmap' => 'Defensive Security', 'completed' => 4, 'start_days_ago' => 19],
                ['roadmap' => 'Cloud Security', 'completed' => 3, 'start_days_ago' => 11],
            ],
        ];

        // Earlier versions of this seeder represented a quiz and a project in
        // the same submission row. Real controllers create separate rows, so
        // remove only those obsolete mock rows before adding realistic data.
        Submission::whereIn('student_id', $students->pluck('id'))
            ->whereNotNull('quiz_id')
            ->whereNotNull('project_id')
            ->get()
            ->filter(fn (Submission $submission) => $this->isMockGenerated($submission))
            ->each->delete();

        foreach ($students as $studentIndex => $student) {
            foreach ($studyPlans[$student->user->email] ?? [] as $planIndex => $plan) {
                $this->seedStudyPlan(
                    $student,
                    $plan,
                    $studentIndex,
                    $planIndex,
                    $professor,
                );
            }
        }

        $this->seedTeams($students);
    }

    /** @param array<string, int|string> $plan */
    private function seedStudyPlan(
        Student $student,
        array $plan,
        int $studentIndex,
        int $planIndex,
        Professor $professor,
    ): void {
        $version = $this->publishedVersion(
            $plan['roadmap'],
            $plan['version'] ?? null,
        );

        if (! $version) {
            return;
        }

        $railNodes = $version->versionNodes()
            ->inRail()
            ->with([
                'node',
                'tutorials',
                'quizzes',
                'projects',
                'choices.node',
                'choices.tutorials',
                'choices.quizzes',
                'choices.projects',
            ])
            ->orderByRaw('COALESCE(stage, 999), id')
            ->get();

        if ($railNodes->isEmpty()) {
            return;
        }

        $completedCount = min((int) $plan['completed'], $railNodes->count());
        $completedNodes = $railNodes->take($completedCount);
        $current = $railNodes->get($completedCount) ?? $railNodes->last();

        $progress = UserRoadmapProgress::updateOrCreate(
            [
                'student_id' => $student->id,
                'roadmap_version_id' => $version->id,
            ],
            [
                'current_node_id' => $current?->node_id,
                'status' => $completedCount >= $railNodes->count()
                    ? 'completed'
                    : 'in_progress',
            ],
        );

        $startDaysAgo = (int) $plan['start_days_ago'];
        $interval = max(1, intdiv(max(1, $startDaysAgo - 2), max(1, $completedCount - 1)));

        foreach ($completedNodes as $nodeIndex => $railNode) {
            $daysAgo = max(1, $startDaysAgo - ($nodeIndex * $interval));
            $completedAt = now()->subDays($daysAgo)->setTime(19, 0);

            CompletedNode::updateOrCreate(
                [
                    'user_roadmap_progress_id' => $progress->id,
                    'node_id' => $railNode->node_id,
                ],
                ['completed_at' => $completedAt],
            );

            // A choice rail is completed by finishing one of its child
            // modules. That selected child carries the actual material.
            $learningNode = $railNode->choices->first() ?? (
                $railNode->links_to_roadmap_id === null ? $railNode : null
            );

            if (! $learningNode) {
                continue;
            }

            if ($learningNode->id !== $railNode->id) {
                CompletedNode::updateOrCreate(
                    [
                        'user_roadmap_progress_id' => $progress->id,
                        'node_id' => $learningNode->node_id,
                    ],
                    ['completed_at' => $completedAt->copy()->subHours(2)],
                );
            }

            $tutorial = $learningNode->tutorials->first(
                fn (Tutorial $item) => $item->review_status === 'approved',
            );
            $quiz = $learningNode->quizzes->first(
                fn (Quiz $item) => $item->review_status === 'approved',
            );
            $project = $learningNode->projects->first(
                fn (Project $item) => $item->review_status === 'approved',
            );
            $activityIndex = ($studentIndex * 30) + ($planIndex * 10) + $nodeIndex;

            if ($tutorial) {
                TutorialCompletion::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'tutorial_id' => $tutorial->id,
                    ],
                    ['completed_at' => $completedAt->copy()->subDays(2)],
                );
            }

            if ($quiz) {
                $this->seedQuizAttempt(
                    $student,
                    $quiz,
                    $activityIndex,
                    $completedAt->copy()->subDay(),
                );
            }

            if ($project) {
                $this->seedProjectSubmission(
                    $student,
                    $project,
                    $activityIndex,
                    $completedAt->copy()->subHours(4),
                    $professor,
                );
            }
        }

        $this->seedTodoTasks(
            $student->user,
            $version->roadmap->title,
            $studentIndex + $planIndex,
        );
    }

    private function seedQuizAttempt(
        Student $student,
        Quiz $quiz,
        int $activityIndex,
        $submittedAt,
    ): void {
        $scores = [76, 82, 89, 94, 78, 86, 91, 97, 84, 88];
        $score = $scores[$activityIndex % count($scores)];

        $submission = Submission::updateOrCreate(
            [
                'quiz_id' => $quiz->id,
                'student_id' => $student->id,
                'project_id' => null,
            ],
            [
                'content' => json_encode([
                    'mock_attempt' => true,
                    'answered_questions' => $quiz->questions()->count(),
                ]),
                'github_url' => null,
                'file_path' => null,
                'status' => 'submitted',
                'score' => $score,
                'ai_score' => null,
                'ai_feedback' => null,
                'ai_reviewed_at' => null,
                'comments' => null,
                'ai_metadata' => [
                    'source' => self::SEED_SOURCE,
                    'mock' => true,
                ],
            ],
        );

        $submission->forceFill([
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ])->saveQuietly();
    }

    private function seedProjectSubmission(
        Student $student,
        Project $project,
        int $activityIndex,
        $submittedAt,
        Professor $professor,
    ): void {
        $states = [
            [
                'status' => 'human_reviewed',
                'score' => 94,
                'ai_score' => 89,
                'ai_feedback' => 'Strong implementation and clear documentation. '
                    .'Add one more edge-case test for production readiness.',
                'comments' => 'Professor confirmed the solution and improved the final score.',
                'review' => 'reviewed',
            ],
            [
                'status' => 'ai_reviewed',
                'score' => null,
                'ai_score' => 86,
                'ai_feedback' => 'The main requirements are satisfied. Error handling '
                    .'and README setup instructions could be more detailed.',
                'comments' => null,
                'review' => 'pending',
            ],
            [
                'status' => 'ai_reviewed',
                'score' => null,
                'ai_score' => 79,
                'ai_feedback' => 'The solution works, but testing and explanation of '
                    .'design decisions should be strengthened.',
                'comments' => null,
                'review' => null,
            ],
            [
                'status' => 'human_reviewed',
                'score' => 88,
                'ai_score' => 83,
                'ai_feedback' => 'Good coverage of the required features and a clear project structure.',
                'comments' => null,
                'review' => 'reviewed',
            ],
        ];

        $state = $states[$activityIndex % count($states)];

        $submission = Submission::updateOrCreate(
            [
                'project_id' => $project->id,
                'student_id' => $student->id,
                'quiz_id' => null,
            ],
            [
                'content' => 'Completed the project requirements and documented the '
                    .'implementation, testing approach, and design decisions.',
                'github_url' => 'https://github.com/cs-mastery-demo/'
                    .str($student->user->name)->slug()
                    .'-'.str($project->title)->slug(),
                'file_path' => null,
                'status' => $state['status'],
                'score' => $state['score'],
                'ai_score' => $state['ai_score'],
                'ai_feedback' => $state['ai_feedback'],
                'ai_reviewed_at' => $state['ai_score'] !== null
                    ? $submittedAt->copy()->addHours(2)
                    : null,
                'comments' => $state['comments'],
                'ai_metadata' => [
                    'source' => self::SEED_SOURCE,
                    'mock' => true,
                ],
            ],
        );

        $submission->forceFill([
            'created_at' => $submittedAt,
            'updated_at' => $submittedAt,
        ])->saveQuietly();

        HumanReviewRequest::where('submission_id', $submission->id)->delete();

        if ($state['review'] === 'reviewed') {
            HumanReviewRequest::updateOrCreate(
                ['submission_id' => $submission->id],
                [
                    'professor_id' => $professor->id,
                    'status' => 'reviewed',
                    'feedback' => 'Good work. The final score reflects the clear '
                    .'implementation and the improvements made after feedback.',
                ],
            );
        } elseif ($state['review'] === 'pending') {
            HumanReviewRequest::updateOrCreate(
                ['submission_id' => $submission->id],
                [
                    'professor_id' => null,
                    'status' => 'pending',
                    'feedback' => null,
                ],
            );
        }
    }

    private function seedTodoTasks(User $user, string $roadmapTitle, int $index): void
    {
        $tasks = [
            [
                'title' => 'Continue '.$roadmapTitle,
                'description' => 'Complete the next tutorial and knowledge check.',
                'days' => 2,
                'priority' => 'high',
                'completed' => false,
            ],
            [
                'title' => 'Review project feedback',
                'description' => 'Apply the latest assessment suggestions to the project.',
                'days' => 4,
                'priority' => 'medium',
                'completed' => $index === 0,
            ],
        ];

        foreach ($tasks as $task) {
            ToDoTask::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'title' => $task['title'],
                ],
                [
                    'description' => $task['description'],
                    'due_date' => now()->addDays($task['days'])->toDateString(),
                    'is_completed' => $task['completed'],
                    'priority' => $task['priority'],
                    'reminder_time' => '18:00:00',
                ],
            );
        }
    }

    /**
     * @param  Collection<int, Student>  $students
     */
    private function seedTeams(Collection $students): void
    {
        if ($students->count() < 3) {
            return;
        }

        $teams = [
            [
                'name' => 'AI Builders',
                'description' => 'A team building practical AI applications and '
                    .'sharing evaluation results.',
                'focus' => 'RAG Study Assistant',
                'members' => [0, 1, 2],
                'messages' => [
                    [0, 'I added the retrieval pipeline tasks to our project board.'],
                    [1, 'I will prepare the evaluation dataset and baseline questions.'],
                    [2, 'I can work on the interface and document the integration.'],
                ],
            ],
            [
                'name' => 'Secure Coders',
                'description' => 'Students collaborating on secure software projects.',
                'focus' => 'Secure Portfolio Review',
                'members' => [0, 2],
                'messages' => [
                    [2, 'The first security review checklist is ready.'],
                    [0, 'Great, I will test it against the sample application.'],
                ],
            ],
        ];

        foreach ($teams as $fixture) {
            $creator = $students[$fixture['members'][0]];

            $team = Team::updateOrCreate(
                ['name' => $fixture['name']],
                [
                    'description' => $fixture['description'],
                    'created_by_student_id' => $creator->id,
                    'project_focus' => $fixture['focus'],
                ],
            );

            foreach ($fixture['members'] as $studentIndex) {
                TeamMember::firstOrCreate([
                    'team_id' => $team->id,
                    'student_id' => $students[$studentIndex]->id,
                ]);
            }

            foreach ($fixture['messages'] as [$studentIndex, $body]) {
                TeamMessage::firstOrCreate([
                    'team_id' => $team->id,
                    'student_id' => $students[$studentIndex]->id,
                    'body' => $body,
                ]);
            }
        }
    }

    private function publishedVersion(
        string $roadmapTitle,
        ?string $versionNumber = null,
    ): ?RoadmapVersion {
        return RoadmapVersion::query()
            ->with('roadmap')
            ->whereHas('roadmap', fn ($query) => $query->where('title', $roadmapTitle))
            ->when(
                $versionNumber,
                fn ($query) => $query->where('version_number', $versionNumber),
            )
            ->where('status', 'Published')
            ->orderByDesc('id')
            ->first();
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedFields(User $reviewer): array
    {
        return [
            'review_status' => 'approved',
            'reviewed_by' => $reviewer->id,
            'reviewed_at' => now(),
            'review_notes' => 'Approved mock data for the CS Mastery demonstration.',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function approvedContentFields(
        User $reviewer,
        VersionNode $versionNode,
    ): array {
        return [
            ...$this->approvedFields($reviewer),
            'ai_metadata' => $this->mockMetadata($versionNode),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function mockMetadata(VersionNode $versionNode): array
    {
        return [
            'source' => self::SEED_SOURCE,
            'mock' => true,
            'version_node_id' => $versionNode->id,
            'node_id' => $versionNode->node_id,
        ];
    }
}
