<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\RoadmapVersion;
use App\Services\AiWorkflowService;
use App\Models\RoadmapAiWorkflow;
use App\Http\Requests\ResumeAiWorkflowRequest;

class AiWorkflowController extends Controller
{
    public function __construct(
        private AiWorkflowService $aiWorkflowService
    ) 
    {

    }

    public function show(RoadmapAiWorkflow $workflow)
    {
        $response = $this->aiWorkflowService
            ->getWorkflow($workflow->thread_id);

        return response()->json(
            $response->json(),
            $response->status()
        );
    }

    public function resume(ResumeAiWorkflowRequest $request,RoadmapAiWorkflow $workflow) 
    {
        $response = $this->aiWorkflowService->resumeWorkflow(
            $workflow->thread_id,
            $request->validated()
        );

        if ($response->successful()) {
            $data = $response->json();

            $workflow->update([
                'status' => $data['status'],
                'review_stage' => $data['review_stage'] ?? null,
            ]);
        }

        return response()->json(
            $response->json(),
            $response->status()
        );
    }

    
}