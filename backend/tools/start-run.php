<?php

/**
 * Start an AI workflow run from the command line.
 *
 * There is no button for this in the UI yet, so this stands in while testing.
 * Run it with:
 *
 *     php artisan tinker tools/start-run.php
 *
 * It uses whichever service AI_SERVICE_URL points at - the stub by default.
 */

use App\Http\Controllers\Api\RoadmapController;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Services\AiWorkflowService;

// Web Development v1. Change this to run against a different version.
$versionId = 1;

$version = RoadmapVersion::find($versionId);

if (! $version) {
    echo "No version with id {$versionId}.\n";
    return;
}

$payload = app(RoadmapController::class)->aiExport($version)->getData(true);

echo "sending " . count($payload['nodes']) . " nodes, "
    . count($payload['edges']) . " edges to the AI service...\n";

try {
    $response = app(AiWorkflowService::class)->startWorkflow($payload);
} catch (Throwable $e) {
    echo "Could not reach the AI service. Is the stub running on 8001?\n";
    echo $e->getMessage() . "\n";
    return;
}

if (! $response->successful()) {
    echo "AI service returned {$response->status()}: {$response->body()}\n";
    return;
}

$data = $response->json();

$workflow = RoadmapAiWorkflow::create([
    'roadmap_version_id' => $version->id,
    'thread_id'          => $data['thread_id'],
    'status'             => $data['status'],
    'review_stage'       => $data['review_stage'] ?? null,
]);

echo "\n";
echo "workflow id : {$workflow->id}\n";
echo "thread id   : {$data['thread_id']}\n";
echo "status      : {$data['status']}\n";
echo "\nOpen it in the Material Manager dashboard under AI Proposals.\n";
