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

}