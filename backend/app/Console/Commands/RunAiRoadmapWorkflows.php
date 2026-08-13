<?php

namespace App\Console\Commands;

use App\Services\AiWorkflowService;
use Illuminate\Console\Command;
use App\Models\RoadmapVersion;
use App\Http\Controllers\Api\RoadmapController;
use App\Models\RoadmapAiWorkflow;

class RunAiRoadmapWorkflows extends Command
{
    protected $signature = 'ai-workflow:run{--roadmap-version== : Run only one roadmap version ID}';

    protected $description = 'Run the daily AI roadmap workflows';

   public function handle(AiWorkflowService $aiWorkflowService): int
    {
        $query = RoadmapVersion::where('status', 'Published');

        if ($versionId = $this->option('roadmap-version')) {
            $query->whereKey($versionId);
        }

        $versions = $query->get();

        $activeStatuses = [
            'awaiting_review',
            'ready_for_commit',
        ];

        foreach ($versions as $version) {
            $hasActiveWorkflow = $version->aiWorkflows()
                ->whereIn('status', $activeStatuses)
                ->exists();

            if ($hasActiveWorkflow) {
                $this->warn(
                    'Version ' . $version->id
                    . ' skipped: an active AI workflow already exists.'
                );

                continue;
            }

            $this->info(
                'Starting AI workflow for version ' . $version->id . '...'
            );

            $exportResponse = app(RoadmapController::class)
                ->aiExport($version);

            $payload = $exportResponse->getData(true);

            $response = $aiWorkflowService->startWorkflow($payload);

            if (! $response->successful()) {
                $this->error(
                    'Version ' . $version->id
                    . ' failed. HTTP ' . $response->status()
                );

                continue;
            }

            $data = $response->json();

            $workflow = RoadmapAiWorkflow::create([
                'roadmap_version_id' => $version->id,
                'thread_id' => $data['thread_id'],
                'status' => $data['status'],
                'review_stage' => $data['review_stage'] ?? null,
            ]);

            $this->info(
                'Version ' . $version->id
                . ' workflow started. Thread: '
                . $workflow->thread_id
            );
        }

        return self::SUCCESS;
    }
}