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


}