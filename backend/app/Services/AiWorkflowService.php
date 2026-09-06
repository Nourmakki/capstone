<?php

namespace App\Services;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;

class AiWorkflowService
{
    private string $baseUrl;

    public function __construct()
    {
        $this->baseUrl = rtrim(
            (string) config('services.ai_system.url'),
            '/'
        );
    }

    /**
     * Let PHP wait as long as the HTTP call is willing to.
     *
     * The timeouts below are sized to the work behind each call - up to five
     * minutes for a generation run. PHP's own execution limit is separate and
     * shorter under `artisan serve`, and it wins: a manager approving a
     * roadmap got "Maximum execution time of 120 seconds exceeded" and a 500
     * with no message, while her service carried on and finished the work.
     * The request had already been killed, so the answer arrived nowhere and
     * the screen said the approval had failed when it had not.
     *
     * Given a few seconds of headroom over the socket timeout, so the HTTP
     * client is always the thing that gives up first and can report why.
     */
    private function allowTimeFor(int $seconds): void
    {
        if (function_exists('set_time_limit')) {
            @set_time_limit($seconds + 30);
        }
    }

    public function healthCheck(): Response
    {
        $this->allowTimeFor(10);

        return Http::timeout(10)
            ->get($this->baseUrl . '/health');
    }


    public function startWorkflow(array $payload): Response
    {
        $this->allowTimeFor(180);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(180)
            ->post(
                $this->baseUrl . '/roadmap-workflows',
                $payload
            );
    }

    public function getWorkflow(string $threadId): Response
    {
        $this->allowTimeFor(30);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get(
                $this->baseUrl . '/roadmap-workflows/' . $threadId
            );
    }

    public function resumeWorkflow(string $threadId,array $decision): Response 
    {
        $this->allowTimeFor(180);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(180)
            ->post(
                $this->baseUrl
                . '/roadmap-workflows/'
                . $threadId
                . '/resume',
                $decision
            );
    }

    /**
     * The learning workflow generates a tutorial, quiz, project and external
     * resources for one module. Separate endpoints from the roadmap workflow,
     * same start / resume / read shape.
     */
    public function startLearningWorkflow(array $payload): Response
    {
        $this->allowTimeFor(300);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(300)
            ->post($this->baseUrl . '/learning-workflows', $payload);
    }

    public function getLearningWorkflow(string $threadId): Response
    {
        $this->allowTimeFor(30);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get($this->baseUrl . '/learning-workflows/' . $threadId);
    }

    public function resumeLearningWorkflow(string $threadId, array $decision): Response
    {
        $this->allowTimeFor(300);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(300)
            ->post(
                $this->baseUrl . '/learning-workflows/' . $threadId . '/resume',
                $decision
            );
    }

    /**
     * Grade one student submission.
     *
     * Unlike the other two workflows this one is multipart, not JSON: her
     * endpoint takes the submitted file itself rather than a path, which is
     * what lets the two halves sit on different machines.
     *
     * Her validator rejects a contradictory pair outright, so the fields are
     * omitted rather than sent null - "file" with a repository_url present is
     * a 422 even when that url is null.
     *
     * @param  array{learning_thread_id: string, submission_type: string,
     *               repository_url?: string, student_note?: string} $fields
     * @param  string|null $filePath Absolute path to the stored submission.
     */
    public function startProjectAssessment(
        array $fields,
        ?string $filePath = null,
        ?string $fileName = null
    ): Response {
        $this->allowTimeFor(600);

        $request = Http::acceptJson()
            ->connectTimeout(5)
            // Cloning a repository and reading a notebook is slower than the
            // other two, and a timeout here loses the student's grade.
            ->timeout(600);

        if ($filePath !== null) {
            $request = $request->attach(
                'submission_file',
                file_get_contents($filePath),
                $fileName ?? basename($filePath)
            );
        }

        return $request->asMultipart()
            ->post($this->baseUrl . '/project-assessment-workflows', $fields);
    }

    public function getProjectAssessment(string $threadId): Response
    {
        $this->allowTimeFor(30);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get($this->baseUrl . '/project-assessment-workflows/' . $threadId);
    }

    /**
     * Build a roadmap from nothing but a topic.
     *
     * The fourth workflow, and the one that fills the gap the others leave:
     * startWorkflow() extends a graph and refuses an empty one, so a brand new
     * roadmap had to be seeded by hand before the AI could touch it. This
     * takes "web development" and returns paths, modules and choices.
     *
     * The longest timeout of the four: it researches a whole subject, splits
     * it into paths, and generates a roadmap for each.
     */
    public function startRoadmapBuilder(string $topic): Response
    {
        $this->allowTimeFor(900);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(900)
            ->post($this->baseUrl . '/roadmap-builder-workflows', [
                'roadmap_topic' => $topic,
            ]);
    }

    public function getRoadmapBuilder(string $threadId): Response
    {
        $this->allowTimeFor(30);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get($this->baseUrl . '/roadmap-builder-workflows/' . $threadId);
    }

    /**
     * Answer one of her two review stages - scope, then roadmap.
     *
     * @param array{action: string, stage?: string, data?: array, feedback?: string} $decision
     */
    public function resumeRoadmapBuilder(string $threadId, array $decision): Response
    {
        $this->allowTimeFor(900);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(900)
            ->post(
                $this->baseUrl . '/roadmap-builder-workflows/' . $threadId . '/resume',
                $decision
            );
    }

    /**
     * Ask the workshop agent what is on near a student, for a topic.
     *
     * A web search and a read of what it finds, so minutes rather than the
     * quarter-hour a roadmap takes - but well past a request's usual patience.
     *
     * student_location is part of her contract and this client can send it,
     * but nothing on the platform does: a manager approves what is worth
     * approving, and the student page narrows it per student. Searching near
     * one place would decide for everybody at once. Sent only when given,
     * because her model forbids extra keys and a null would claim the student
     * has no location rather than that we were not told one.
     */
    public function startWorkshopWorkflow(string $topic, ?string $location = null): Response
    {
        $this->allowTimeFor(300);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(300)
            ->post($this->baseUrl . '/workshop-workflows', array_filter([
                'roadmap_topic'    => $topic,
                'student_location' => $location,
            ], fn ($value) => $value !== null));
    }

    public function getWorkshopWorkflow(string $threadId): Response
    {
        $this->allowTimeFor(30);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get($this->baseUrl . '/workshop-workflows/' . $threadId);
    }

    /**
     * Approve or reject what it found.
     *
     * Her workshop review takes only those two - no edit, no regenerate -
     * and an approve may carry a list of links to approve only some.
     *
     * @param array{action: string, data?: array, feedback?: string} $decision
     */
    public function resumeWorkshopWorkflow(string $threadId, array $decision): Response
    {
        $this->allowTimeFor(300);

        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(300)
            ->post(
                $this->baseUrl . '/workshop-workflows/' . $threadId . '/resume',
                $decision
            );
    }
}