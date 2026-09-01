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

    public function healthCheck(): Response
    {
        return Http::timeout(10)
            ->get($this->baseUrl . '/health');
    }


    public function startWorkflow(array $payload): Response
    {
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
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get(
                $this->baseUrl . '/roadmap-workflows/' . $threadId
            );
    }

    public function resumeWorkflow(string $threadId,array $decision): Response 
    {
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
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(300)
            ->post($this->baseUrl . '/learning-workflows', $payload);
    }

    public function getLearningWorkflow(string $threadId): Response
    {
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(30)
            ->get($this->baseUrl . '/learning-workflows/' . $threadId);
    }

    public function resumeLearningWorkflow(string $threadId, array $decision): Response
    {
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
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(900)
            ->post($this->baseUrl . '/roadmap-builder-workflows', [
                'roadmap_topic' => $topic,
            ]);
    }

    public function getRoadmapBuilder(string $threadId): Response
    {
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
        return Http::acceptJson()
            ->connectTimeout(5)
            ->timeout(900)
            ->post(
                $this->baseUrl . '/roadmap-builder-workflows/' . $threadId . '/resume',
                $decision
            );
    }
}