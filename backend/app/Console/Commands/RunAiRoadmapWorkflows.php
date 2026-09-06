<?php

namespace App\Console\Commands;

use App\Services\AiWorkflowService;
use Illuminate\Console\Command;
use Illuminate\Http\Client\ConnectionException;
use App\Models\AiSchedule;
use App\Models\RoadmapVersion;
use App\Http\Controllers\Api\RoadmapController;
use App\Models\RoadmapAiWorkflow;

/**
 * Send published roadmaps to the AI to be asked what is new in their subject.
 *
 * What it takes on comes from the schedule row - which versions, and how many
 * at most - so the same limits apply whether the scheduler fired it or
 * somebody pressed Run now. Every published roadmap at once is right for a
 * nightly job and wrong in front of an audience, where fifteen runs is
 * minutes of waiting and a queue nobody can read through.
 *
 * --roadmap-version overrides all of that, for running one by hand.
 */
class RunAiRoadmapWorkflows extends Command
{
    protected $signature = 'ai-workflow:run
                            {--roadmap-version= : Run only this roadmap version ID, ignoring the saved scope}';

    protected $description = 'Ask the AI what is new in each roadmap subject';

    /** What the last run did, for the page that started it. */
    public array $summary = [
        'started' => 0,
        'skipped' => 0,
        'failed'  => 0,
        'lines'   => [],
    ];

    public function handle(AiWorkflowService $ai): int
    {
        $versions = $this->versionsToScan();

        if ($versions->isEmpty()) {
            $this->note('Nothing to scan: no published roadmap matched.');

            return self::SUCCESS;
        }

        $activeStatuses = ['awaiting_review', 'ready_for_commit'];

        foreach ($versions as $version) {
            $name = $version->roadmap->title ?? ('version ' . $version->id);

            $hasActiveWorkflow = $version->aiWorkflows()
                ->whereIn('status', $activeStatuses)
                ->exists();

            if ($hasActiveWorkflow) {
                $this->summary['skipped']++;
                $this->note("{$name}: skipped, it already has a run waiting for review.");

                continue;
            }

            $payload = app(RoadmapController::class)->aiExport($version)->getData(true);

            try {
                $response = $ai->startWorkflow($payload);
            } catch (ConnectionException) {
                // Every version after this one would fail the same way, and
                // a page full of "not reachable" says less than one line.
                $this->summary['failed']++;
                $this->note('Stopped: the AI service is not reachable.');

                return self::FAILURE;
            }

            if (! $response->successful()) {
                $this->summary['failed']++;
                $this->note("{$name}: failed, HTTP {$response->status()}.");

                continue;
            }

            $data = $response->json();

            RoadmapAiWorkflow::create([
                'roadmap_version_id' => $version->id,
                'thread_id'          => $data['thread_id'],
                'status'             => $data['status'],
                'review_stage'       => $data['review_stage'] ?? null,
            ]);

            $this->summary['started']++;
            $this->note("{$name}: started.");
        }

        return self::SUCCESS;
    }

    /**
     * One named version, or whatever the saved scope says.
     */
    private function versionsToScan()
    {
        if ($versionId = $this->option('roadmap-version')) {
            return RoadmapVersion::with('roadmap')
                ->where('status', 'Published')
                ->whereKey($versionId)
                ->get();
        }

        return AiSchedule::current()->versionsInScope()->load('roadmap');
    }

    /** To the terminal and to the caller, which may be a page. */
    private function note(string $line): void
    {
        $this->summary['lines'][] = $line;

        $this->info($line);
    }
}
