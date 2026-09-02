<?php

namespace App\Jobs;

use App\Models\VersionNode;
use App\Services\LearningRunLauncher;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Ask the AI to write one module's material, on the queue.
 *
 * Her learning endpoint is synchronous: `start_learning_workflow()` runs the
 * whole graph - tutorial, quiz, project and resources - before it answers,
 * which is why the timeout on that call is five minutes. Committing a built
 * subject can create sixty modules, and starting them inline would hold one
 * HTTP request open for hours and then die on PHP's execution limit long
 * before finishing.
 *
 * One job per module instead, so the commit returns immediately and the
 * material arrives over the following minutes.
 *
 * Needs a worker: `php artisan queue:work`. Without one the jobs sit in the
 * table and the modules simply stay coming-soon, which is the same state
 * they would be in if the AI service were unreachable - nothing breaks, the
 * material just does not arrive.
 */
class StartLearningRun implements ShouldQueue
{
    use Queueable;

    /**
     * One try. A retry would ask the AI to write the same material a second
     * time, and the launcher already refuses a module that has a run in
     * flight - so a failure is better left for a manager to restart by hand.
     */
    public int $tries = 1;

    // Her call can take five minutes; the job must outlast it.
    public int $timeout = 420;

    public function __construct(public int $versionNodeId)
    {
    }

    public function handle(LearningRunLauncher $launcher): void
    {
        $versionNode = VersionNode::find($this->versionNodeId);

        if (! $versionNode) {
            return;
        }

        // Returns null rather than throwing when the service is unreachable,
        // so an outage does not fill the failed_jobs table with work that
        // will never be retried anyway.
        $launcher->start($versionNode);
    }
}
