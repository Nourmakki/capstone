<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiWorkflowController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\RoadmapController;
use App\Http\Controllers\Api\TutorialController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

/*
|--------------------------------------------------------------------------
| Authenticated routes
|--------------------------------------------------------------------------
*/
Route::middleware('auth:sanctum')->group(function () {

    Route::post('/logout', [AuthController::class, 'logout']);
    Route::get('/me', [AuthController::class, 'me']);

    /*
    |----------------------------------------------------------------------
    | Roadmaps and learning content
    |----------------------------------------------------------------------
    */
    Route::get('/roadmaps', [RoadmapController::class, 'index']);
    Route::get('/roadmaps/{roadmap}', [RoadmapController::class, 'show']);
    Route::get('/roadmaps/{roadmap}/versions', [RoadmapController::class, 'versions']);
    Route::get('/roadmaps/{roadmap}/latest-version', [RoadmapController::class, 'latestVersion']);
    Route::get('/roadmap-versions/{version}/graph', [RoadmapController::class, 'graph']);
    Route::get('/version-nodes/{versionNodeId}/tutorials', [TutorialController::class, 'index']);

    // Everything LearningPage needs for one module.
    Route::get('/version-nodes/{versionNode}', [LearningController::class, 'nodeDetail']);

    /*
    |----------------------------------------------------------------------
    | Progress
    |----------------------------------------------------------------------
    */
    Route::get('/roadmaps/{roadmap}/student/{user}', [ProgressController::class, 'studentRoadmap']);
    Route::get('/progress/continue', [LearningController::class, 'continueLearning']);
    Route::post('/progress/complete-node', [ProgressController::class, 'completeNode']);

    /*
    |----------------------------------------------------------------------
    | Admin only
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin')->group(function () {

        Route::post('/roadmaps', [RoadmapController::class, 'store']);
        Route::put('/roadmaps/{roadmap}', [RoadmapController::class, 'update']);
        Route::delete('/roadmaps/{roadmap}', [RoadmapController::class, 'destroy']);

        // Feeds the LangGraph AI service its AgentState input.
        Route::get('/roadmap-versions/{version}/ai-export', [RoadmapController::class, 'aiExport']);
        Route::get('/ai-workflows/{workflow}',[AiWorkflowController::class, 'show']);
        Route::post('/ai-workflows/{workflow}/resume',[AiWorkflowController::class, 'resume']);
        Route::prefix('admin')->group(function () {
            Route::get('/professor-requests', [AdminController::class, 'professorRequests']);
            Route::put('/approve-professor/{id}', [AdminController::class, 'approveProfessor']);
            Route::delete('/reject-professor/{id}', [AdminController::class, 'rejectProfessor']);
            Route::get('/dashboard-stats', [AdminController::class, 'dashboardStats']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | Admin and Professor
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin,Professor')->group(function () {
        Route::apiResource('tutorials', TutorialController::class)->except(['index']);
    });
});