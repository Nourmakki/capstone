<?php

namespace App\Jobs;

use App\Models\Submission;
use App\Services\AiSubmissionAssessmentService;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Grade a project submission with the AI, on the queue.
 *
 * Handing work in is what starts this. A student used to submit and then wait
 * for somebody to remember to press a button - and since nobody did, four
 * submissions sat ungraded with no sign anything was meant to happen.
 *
 * Queued, not inline. Her assessment clones the repository and reads the
 * files before it answers, which is why the timeout on that call is ten
 * minutes; running it inside the submit request would hold the student's
 * browser open for all of it and then die on PHP's execution limit.
 *
 * Nothing here can cost a student their submission. The row is already
 * written and committed by the time this runs, so every failure below leaves
 * a submitted-but-ungraded project - exactly the state the platform was in
 * before, and one a professor can still grade by hand.
 *
 * Needs a worker: `php artisan queue:work`. Without one the job waits in the
 * table and the submission stays ungraded until someone runs it.
 */
class AssessSubmission implements ShouldQueue
{
    use Queueable;

    /**
     * One try.
     *
     * A retry re-clones the repository and spends the quota again, and the
     * failures this actually hits are not the kind that pass on a second
     * attempt: a module with no learning run to grade against will still have
     * none in thirty seconds. The manual assess endpoint is the retry.
     */
    public int $tries = 1;

    /** Her call can take ten minutes; the job must outlast it. */
    public int $timeout = 660;

    public function __construct(public int $submissionId)
    {
    }

    public function handle(AiSubmissionAssessmentService $assessment): void
    {
        $submission = Submission::find($this->submissionId);

        if (! $submission) {
            return;
        }

        // Already marked by a person. Their score wins over the AI's anyway,
        // so grading it now would spend a long call to fill a field nobody
        // reads.
        if ($submission->status === 'human_reviewed') {
            return;
        }

        try {
            $assessment->assess($submission);
        } catch (ValidationException $e) {
            // Not a fault: the submission cannot be graded as it stands. The
            // common one is a module whose learning material has not been
            // generated yet, so there are no criteria to grade against.
            Log::info('Submission left ungraded.', [
                'submission_id' => $submission->id,
                'reason'        => collect($e->errors())->flatten()->first(),
            ]);
        } catch (ConnectionException $e) {
            Log::warning('Submission could not be graded: the AI system is unreachable.', [
                'submission_id' => $submission->id,
            ]);
        }
    }
}
