<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompletedNode;
use App\Models\ExternalResources;
use App\Models\Project;
use App\Models\Quiz;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\Student;
use App\Models\Submission;
use App\Models\Tutorial;
use App\Models\TutorialCompletion;
use App\Models\User;
use App\Models\UserRoadmapProgress;
use App\Models\VersionNode;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

class DashboardStatisticsController extends Controller
{
    /** Analytical views of only the signed-in student's learning records. */
    public function student(Request $request)
    {
        $student = Student::where('user_id', $request->user()->id)->firstOrFail();

        $progressRows = UserRoadmapProgress::with('roadmapVersion.roadmap')
            ->where('student_id', $student->id)
            ->orderByDesc('updated_at')
            ->get()
            ->unique(fn (UserRoadmapProgress $progress) => $progress->roadmapVersion->roadmap_id)
            ->values();

        $roadmapProgress = $progressRows->map(function (UserRoadmapProgress $progress) use ($student): array {
            $version = $progress->roadmapVersion;
            $nodeIds = VersionNode::where('roadmap_version_id', $version->id)
                ->inRail()
                ->pluck('node_id')
                ->all();

            $completedIds = UserRoadmapProgress::completedNodeIdsFor(
                $student->id,
                $version->roadmap_id,
            );

            $completed = count(array_intersect($nodeIds, $completedIds));
            $total = count($nodeIds);

            return [
                'label' => $version->roadmap->title,
                'version' => $version->version_number,
                'total_nodes' => $total,
                'completed_nodes' => $completed,
                'remaining_nodes' => max(0, $total - $completed),
                'progress_percent' => $total > 0
                    ? (int) round(($completed / $total) * 100)
                    : 0,
            ];
        })->values();

        $activityStart = CarbonImmutable::today()->subDays(29);

        $activityTimeline = $this->dailyTimeline([
            'Modules completed' => CompletedNode::whereHas(
                'userRoadmapProgress',
                fn ($query) => $query->where('student_id', $student->id),
            )->where('completed_at', '>=', $activityStart)->pluck('completed_at'),
            'Tutorials completed' => TutorialCompletion::where('student_id', $student->id)
                ->where('completed_at', '>=', $activityStart)
                ->pluck('completed_at'),
            'Projects submitted' => Submission::where('student_id', $student->id)
                ->whereNotNull('project_id')
                ->where('created_at', '>=', $activityStart)
                ->pluck('created_at'),
        ], 30);

        $quizScores = Submission::with('quiz.versionNode.node')
            ->where('student_id', $student->id)
            ->whereNotNull('quiz_id')
            ->where(fn ($query) => $query->whereNotNull('score')->orWhereNotNull('ai_score'))
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (Submission $submission): array => [
                'label' => $submission->quiz?->versionNode?->node?->title ?? 'Quiz',
                'type' => 'Quiz',
                'score' => $submission->finalScore(),
                'date' => $submission->created_at?->toDateString(),
            ]);

        $projectScores = Submission::with('project.versionNode.node')
            ->where('student_id', $student->id)
            ->whereNotNull('project_id')
            ->where(fn ($query) => $query->whereNotNull('score')->orWhereNotNull('ai_score'))
            ->latest()
            ->take(8)
            ->get()
            ->map(fn (Submission $submission): array => [
                'label' => $submission->project?->versionNode?->node?->title ?? 'Project',
                'type' => 'Project',
                'score' => $submission->finalScore(),
                'date' => $submission->created_at?->toDateString(),
            ]);

        $learningMix = [
            [
                'label' => 'Tutorials completed',
                'value' => TutorialCompletion::where('student_id', $student->id)->count(),
            ],
            [
                'label' => 'Quizzes passed',
                'value' => Submission::where('student_id', $student->id)
                    ->whereNotNull('quiz_id')
                    ->where('score', '>=', QuizController::PASS_MARK)
                    ->distinct()
                    ->count('quiz_id'),
            ],
            [
                'label' => 'Projects submitted',
                'value' => Submission::where('student_id', $student->id)
                    ->whereNotNull('project_id')
                    ->distinct()
                    ->count('project_id'),
            ],
        ];

        return response()->json([
            'roadmap_progress' => $roadmapProgress,
            'activity_timeline' => $activityTimeline,
            'assessment_scores' => $quizScores
                ->concat($projectScores)
                ->sortBy('date')
                ->values(),
            'learning_mix' => $learningMix,
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** Platform-wide membership, growth, engagement, and roadmap adoption. */
    public function admin()
    {
        $growthStart = CarbonImmutable::now()->startOfMonth()->subMonths(5);

        $userGrowth = $this->monthlyTimeline([
            'Students' => User::where('role', 'Student')
                ->where('created_at', '>=', $growthStart)
                ->pluck('created_at'),
            'Professors' => User::whereIn('role', ['Professor', 'PendingProfessor'])
                ->where('created_at', '>=', $growthStart)
                ->pluck('created_at'),
        ], 6);

        $activityStart = CarbonImmutable::today()->subDays(29);

        $engagementTimeline = $this->dailyTimeline([
            'Module completions' => CompletedNode::where('completed_at', '>=', $activityStart)
                ->pluck('completed_at'),
            'Tutorial completions' => TutorialCompletion::where('completed_at', '>=', $activityStart)
                ->pluck('completed_at'),
            'Project submissions' => Submission::whereNotNull('project_id')
                ->where('created_at', '>=', $activityStart)
                ->pluck('created_at'),
        ], 30);

        return response()->json([
            'user_growth' => $userGrowth,
            'role_distribution' => [
                ['label' => 'Students', 'value' => User::where('role', 'Student')->count()],
                ['label' => 'Professors', 'value' => User::where('role', 'Professor')->count()],
                ['label' => 'Pending professors', 'value' => User::where('role', 'PendingProfessor')->count()],
                ['label' => 'Material managers', 'value' => User::where('role', 'MaterialManager')->count()],
                ['label' => 'Administrators', 'value' => User::where('role', 'Admin')->count()],
            ],
            'engagement_timeline' => $engagementTimeline,
            'roadmap_engagement' => $this->roadmapEngagement(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** Content quality, review flow, and roadmap readiness for managers. */
    public function material()
    {
        $types = $this->reviewTypes();

        $reviewStatus = collect($types)->map(
            fn (string $model, string $label): array => [
                'label' => $label,
                'approved' => $model::approved()->count(),
                'pending' => $model::pendingReview()->count(),
                'rejected' => $model::where('review_status', 'rejected')->count(),
            ],
        )->values();

        $decisionStart = CarbonImmutable::now()->startOfWeek()->subWeeks(7);
        $approvedEvents = collect();
        $rejectedEvents = collect();
        $pendingEvents = collect();

        foreach ($types as $model) {
            $approvedEvents = $approvedEvents->concat(
                $model::where('review_status', 'approved')
                    ->where('reviewed_at', '>=', $decisionStart)
                    ->pluck('reviewed_at'),
            );
            $rejectedEvents = $rejectedEvents->concat(
                $model::where('review_status', 'rejected')
                    ->where('reviewed_at', '>=', $decisionStart)
                    ->pluck('reviewed_at'),
            );
            $pendingEvents = $pendingEvents->concat(
                $model::pendingReview()
                    ->where('created_at', '>=', $decisionStart)
                    ->pluck('created_at'),
            );
        }

        return response()->json([
            'review_timeline' => $this->weeklyTimeline([
                'Approved' => $approvedEvents,
                'Rejected' => $rejectedEvents,
                'New pending' => $pendingEvents,
            ], 8),
            'review_status' => $reviewStatus,
            'content_mix' => [
                ['label' => 'Tutorials', 'value' => Tutorial::approved()->count()],
                ['label' => 'Quizzes', 'value' => Quiz::approved()->count()],
                ['label' => 'Projects', 'value' => Project::approved()->count()],
                ['label' => 'Resources', 'value' => ExternalResources::approved()->count()],
            ],
            'roadmap_coverage' => $this->roadmapCoverage(),
            'updated_at' => now()->toIso8601String(),
        ]);
    }

    /** @return Collection<int, array<string, int|string>> */
    private function roadmapEngagement(): Collection
    {
        $published = $this->latestPublishedVersions();
        $roadmapIds = $published->pluck('roadmap_id');
        $allVersions = RoadmapVersion::whereIn('roadmap_id', $roadmapIds)->get(['id', 'roadmap_id']);
        $roadmapByVersion = $allVersions->pluck('roadmap_id', 'id');
        $allVersionIds = $allVersions->pluck('id');

        $progress = UserRoadmapProgress::whereIn('roadmap_version_id', $allVersionIds)
            ->get(['roadmap_version_id', 'student_id'])
            ->groupBy(fn (UserRoadmapProgress $row) => $roadmapByVersion[$row->roadmap_version_id] ?? 0);

        $completionCounts = CompletedNode::with('userRoadmapProgress:id,roadmap_version_id')
            ->whereHas(
                'userRoadmapProgress',
                fn ($query) => $query->whereIn('roadmap_version_id', $allVersionIds),
            )
            ->get()
            ->groupBy(
                fn (CompletedNode $node) => $roadmapByVersion[$node->userRoadmapProgress->roadmap_version_id] ?? 0,
            )
            ->map->count();

        $nodeCounts = VersionNode::whereIn('roadmap_version_id', $published->pluck('id'))
            ->inRail()
            ->get(['roadmap_version_id'])
            ->groupBy('roadmap_version_id')
            ->map->count();

        return $published->map(function (RoadmapVersion $version) use ($progress, $completionCounts, $nodeCounts): array {
            $roadmapProgress = $progress->get($version->roadmap_id, collect());

            return [
                'label' => $version->roadmap->title,
                'nodes' => $nodeCounts->get($version->id, 0),
                'students' => $roadmapProgress->unique('student_id')->count(),
                'completions' => $completionCounts->get($version->roadmap_id, 0),
            ];
        })
            ->filter(fn (array $item) => $item['students'] > 0 || $item['completions'] > 0)
            ->sortByDesc('students')
            ->take(16)
            ->values();
    }

    /** @return Collection<int, array<string, int|string>> */
    private function roadmapCoverage(): Collection
    {
        $versions = RoadmapVersion::whereIn('status', ['Published', 'Draft'])
            ->with('roadmap')
            ->orderByDesc('id')
            ->get()
            ->unique('roadmap_id')
            ->filter(fn (RoadmapVersion $version) => $version->roadmap?->parent_roadmap_id !== null)
            ->values();

        $nodes = VersionNode::with([
            'choices:id,parent_version_node_id',
            'tutorials' => fn ($query) => $query->approved(),
            'quizzes' => fn ($query) => $query->approved(),
            'projects' => fn ($query) => $query->approved(),
        ])
            ->whereIn('roadmap_version_id', $versions->pluck('id'))
            ->inRail()
            ->get()
            ->groupBy('roadmap_version_id');

        return $versions->map(function (RoadmapVersion $version) use ($nodes): array {
            $versionNodes = $nodes->get($version->id, collect());
            $materialNodes = $versionNodes->filter(
                fn (VersionNode $node) => $node->links_to_roadmap_id === null
                    && $node->choices->isEmpty(),
            );

            $ready = $materialNodes->filter(
                fn (VersionNode $node) => $node->tutorials->isNotEmpty()
                    && $node->quizzes->isNotEmpty()
                    && $node->projects->isNotEmpty(),
            )->count();

            $total = $materialNodes->count();

            return [
                'label' => $version->roadmap->title,
                'version' => $version->version_number,
                'status' => $version->status,
                'ready' => $ready,
                'missing' => max(0, $total - $ready),
                'total' => $total,
                'coverage_percent' => $total > 0 ? (int) round(($ready / $total) * 100) : 0,
            ];
        })
            ->sortBy([
                ['coverage_percent', 'asc'],
                ['total', 'desc'],
            ])
            ->take(16)
            ->values();
    }

    /** @return Collection<int, RoadmapVersion> */
    private function latestPublishedVersions(): Collection
    {
        return RoadmapVersion::live()
            ->with('roadmap')
            ->orderByDesc('id')
            ->get()
            ->unique('roadmap_id')
            ->filter(fn (RoadmapVersion $version) => $version->roadmap?->parent_roadmap_id !== null)
            ->values();
    }

    /** @param array<string, Collection> $series */
    private function dailyTimeline(array $series, int $days): array
    {
        $start = CarbonImmutable::today()->subDays($days - 1);
        $keys = collect(range(0, $days - 1))->map(
            fn (int $offset) => $start->addDays($offset)->toDateString(),
        );

        return $this->timelinePayload($keys, $series, 'M j', 'Y-m-d');
    }

    /** @param array<string, Collection> $series */
    private function monthlyTimeline(array $series, int $months): array
    {
        $start = CarbonImmutable::now()->startOfMonth()->subMonths($months - 1);
        $keys = collect(range(0, $months - 1))->map(
            fn (int $offset) => $start->addMonths($offset)->format('Y-m'),
        );

        return $this->timelinePayload($keys, $series, 'M Y', 'Y-m');
    }

    /** @param array<string, Collection> $series */
    private function weeklyTimeline(array $series, int $weeks): array
    {
        $start = CarbonImmutable::now()->startOfWeek()->subWeeks($weeks - 1);
        $keys = collect(range(0, $weeks - 1))->map(
            fn (int $offset) => $start->addWeeks($offset)->toDateString(),
        );

        $normalized = collect($series)->map(
            fn (Collection $events) => $events->map(
                fn ($timestamp) => CarbonImmutable::parse($timestamp)->startOfWeek()->toDateString(),
            ),
        )->all();

        return $this->timelinePayload($keys, $normalized, 'M j', 'Y-m-d');
    }

    /**
     * @param  Collection<int, string>  $keys
     * @param  array<string, Collection>  $series
     */
    private function timelinePayload(
        Collection $keys,
        array $series,
        string $labelFormat,
        string $eventKeyFormat,
    ): array {
        return [
            'labels' => $keys->map(
                fn (string $key) => CarbonImmutable::parse($key)->format($labelFormat),
            )->values(),
            'series' => collect($series)->map(function (Collection $events, string $name) use ($keys, $eventKeyFormat): array {
                $counts = $events->map(
                    fn ($timestamp) => CarbonImmutable::parse($timestamp)->format($eventKeyFormat),
                )->countBy();

                return [
                    'name' => $name,
                    'values' => $keys->map(fn (string $key) => $counts->get($key, 0))->values(),
                ];
            })->values(),
        ];
    }

    /** @return array<string, class-string<Model>> */
    private function reviewTypes(): array
    {
        return [
            'Roadmaps' => Roadmap::class,
            'Versions' => RoadmapVersion::class,
            'Tutorials' => Tutorial::class,
            'Resources' => ExternalResources::class,
            'Quizzes' => Quiz::class,
            'Projects' => Project::class,
        ];
    }
}
