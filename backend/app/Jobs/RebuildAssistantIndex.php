<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

/**
 * Tell the assistant the catalogue has changed.
 *
 * The assistant answers from an index built from published, approved content.
 * Publishing a roadmap or approving a module's material changes what students
 * can see, and until the index is rebuilt the assistant is answering from the
 * catalogue as it was - denying that a roadmap exists which is live on the
 * page next to it.
 *
 * On the queue because rebuilding reads the whole catalogue back out of the
 * API, and a manager pressing Approve should not wait for it.
 *
 * The staleness only ever errs toward less disclosure - an unrebuilt index
 * knows about less than exists, never more - so a failure here is a quality
 * problem, not a leak. That is why it is logged and swallowed rather than
 * retried into a queue full of failures.
 */
class RebuildAssistantIndex implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    /**
     * Collapse a burst into one rebuild.
     *
     * Approving a module's tutorial, quiz and project is three approvals in
     * a row, and publishing a built subject can be eight versions. Each one
     * would queue a rebuild of the same catalogue; this keeps one.
     */
    public function uniqueId(): string
    {
        return 'assistant-index';
    }

    public function handle(): void
    {
        $url = rtrim((string) config('services.assistant.url'), '/') . '/reindex';

        try {
            $response = Http::acceptJson()->connectTimeout(3)->timeout(150)->post($url);

            if ($response->successful()) {
                Log::info('Assistant index rebuilt.', ['chunks' => $response->json('chunks')]);

                return;
            }

            Log::warning('The assistant refused to rebuild its index.', [
                'status' => $response->status(),
            ]);
        } catch (\Throwable $e) {
            // The assistant not running is the ordinary case on a machine
            // where nobody started it, and it must not turn approving
            // material into an error.
            Log::info('Could not reach the assistant to rebuild its index.', [
                'reason' => $e->getMessage(),
            ]);
        }
    }
}
