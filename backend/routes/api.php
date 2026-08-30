<?php

use App\Http\Controllers\Api\AdminController;
use App\Http\Controllers\Api\AiWorkflowController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\LearningController;
use App\Http\Controllers\Api\MaterialController;
use App\Http\Controllers\Api\ProgressController;
use App\Http\Controllers\Api\RoadmapController;
use App\Http\Controllers\Api\ExternalResourceController;
use App\Http\Controllers\Api\TutorialController;
use App\Http\Controllers\Api\QuizController;
use App\Http\Controllers\Api\ProfessorController;
use App\Http\Controllers\Api\ProjectController;
use App\Http\Controllers\Api\TeamController;
use App\Http\Controllers\Api\TodoController;
use App\Http\Controllers\Api\WorkshopController;
use App\Http\Controllers\Api\ProfileController;
use App\Http\Controllers\Api\VersionGraphController;
use App\Http\Controllers\Api\LearningWorkflowController;
use App\Http\Controllers\Api\MaterialAuthoringController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public routes
|--------------------------------------------------------------------------
*/
Route::post('/register', [AuthController::class, 'register']);
Route::post('/login', [AuthController::class, 'login']);

// The landing page shows the roadmaps on offer, which is marketing copy the
// platform already publishes to every logged-in student. Same controller, and
// it already filters a non-manager down to approved roadmaps with a published
// version - a guest simply has no user, which seesUnapproved() now allows for.
Route::get('/public/roadmaps', [RoadmapController::class, 'index']);

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
    Route::get('/version-nodes/{versionNodeId}/external-resources', [ExternalResourceController::class, 'index']);

    // Everything LearningPage needs for one module.
    Route::get('/version-nodes/{versionNode}', [LearningController::class, 'nodeDetail']);

    // Quizzes
    Route::get('/quizzes/{quiz}', [QuizController::class, 'show']);
    Route::post('/quizzes/{quiz}/submit', [QuizController::class, 'submit']);
    Route::get('/quizzes/{quiz}/attempts', [QuizController::class, 'attempts']);

        // Projects
    Route::get('/projects/{project}', [ProjectController::class, 'show']);
    Route::post('/projects/{project}/submit', [ProjectController::class, 'submit']);
    Route::post('/submissions/{submission}/request-review', [ProjectController::class, 'requestReview']);
    Route::get('/submissions/{submission}/download', [ProjectController::class, 'download']);
    
    /*
    |----------------------------------------------------------------------
    | Progress
    |----------------------------------------------------------------------
    */
    Route::get('/roadmaps/{roadmap}/student/{user}', [ProgressController::class, 'studentRoadmap']);
    Route::get('/progress/continue', [LearningController::class, 'continueLearning']);
    Route::post('/progress/complete-node', [ProgressController::class, 'completeNode']);

    // A student's own word that they watched a tutorial - nothing else can
    // tell, and until now nothing recorded it at all.
    Route::post('/tutorials/{tutorial}/complete', [ProgressController::class, 'completeTutorial']);
    Route::delete('/tutorials/{tutorial}/complete', [ProgressController::class, 'uncompleteTutorial']);

        // To-do tasks
    Route::get('/todos', [TodoController::class, 'index']);
    Route::post('/todos', [TodoController::class, 'store']);
    Route::put('/todos/{todo}', [TodoController::class, 'update']);
    Route::patch('/todos/{todo}/toggle', [TodoController::class, 'toggle']);
    Route::delete('/todos/{todo}', [TodoController::class, 'destroy']);

    // Teams
    Route::get('/teams', [TeamController::class, 'index']);
    Route::post('/teams', [TeamController::class, 'store']);
    Route::post('/teams/{team}/join', [TeamController::class, 'join']);
    Route::delete('/teams/{team}/leave', [TeamController::class, 'leave']);
    Route::get('/teams/{team}/messages', [TeamController::class, 'messages']);
    Route::post('/teams/{team}/messages', [TeamController::class, 'postMessage']);

        // Profile
    Route::get('/profile', [ProfileController::class, 'show']);
    Route::put('/profile', [ProfileController::class, 'update']);

    // Workshops
    Route::get('/workshops', [WorkshopController::class, 'index']);
    Route::get('/workshops/recommended', [WorkshopController::class, 'recommended']);

    /*
    |----------------------------------------------------------------------
    | Admin only
    |----------------------------------------------------------------------
    */
    Route::middleware('role:Admin')->group(function () {

        // User administration stays with the admin alone.
        Route::prefix('admin')->group(function () {
            Route::get('/professor-requests', [AdminController::class, 'professorRequests']);
            Route::get('/professor-requests/{id}/cv', [AdminController::class, 'professorCv']);
            Route::put('/approve-professor/{id}', [AdminController::class, 'approveProfessor']);
            Route::delete('/reject-professor/{id}', [AdminController::class, 'rejectProfessor']);
            Route::get('/dashboard-stats', [AdminController::class, 'dashboardStats']);
        });
    });

    /*
    |----------------------------------------------------------------------
    | Roadmap authoring - material managers only
    |----------------------------------------------------------------------
    */
    Route::middleware('role:MaterialManager')->group(function () {

        Route::post('/roadmaps', [RoadmapController::class, 'store']);
        Route::put('/roadmaps/{roadmap}', [RoadmapController::class, 'update']);
        Route::delete('/roadmaps/{roadmap}', [RoadmapController::class, 'destroy']);

        Route::post('/roadmaps/{roadmap}/versions', [RoadmapController::class, 'createVersion']);
        Route::put('/roadmap-versions/{version}/publish', [RoadmapController::class, 'publishVersion']);
        Route::delete('/roadmap-versions/{version}', [RoadmapController::class, 'destroyVersion']);

        // Manual graph authoring: the modules in a draft and what comes before what.
        Route::get('/roadmap-versions/{version}/modules', [VersionGraphController::class, 'index']);
        Route::post('/roadmap-versions/{version}/modules', [VersionGraphController::class, 'store']);
        Route::put('/version-nodes/{versionNode}', [VersionGraphController::class, 'update']);
        Route::delete('/version-nodes/{versionNode}', [VersionGraphController::class, 'destroy']);

        // Correcting the quizzes and projects on a module.
        Route::get('/version-nodes/{versionNode}/material', [MaterialAuthoringController::class, 'index']);

        Route::post('/version-nodes/{versionNode}/quizzes', [MaterialAuthoringController::class, 'storeQuiz']);
        Route::put('/quizzes/{quiz}', [MaterialAuthoringController::class, 'updateQuiz']);
        Route::delete('/quizzes/{quiz}', [MaterialAuthoringController::class, 'destroyQuiz']);

        Route::post('/version-nodes/{versionNode}/projects', [MaterialAuthoringController::class, 'storeProject']);
        Route::put('/projects/{project}', [MaterialAuthoringController::class, 'updateProject']);
        Route::delete('/projects/{project}', [MaterialAuthoringController::class, 'destroyProject']);
        
        // No 'update'. A tutorial is a video somebody else made - the only
        // things a manager can change about it are which video it is and
        // whether it stays, and both of those are covered by regenerate and
        // destroy. Nour's schema takes the same line: LearningReviewStage
        // allows an edit of quiz, project and external_resources, and raises
        // "Tutorial cannot be edited. Use regenerate instead."
        Route::apiResource('tutorials', TutorialController::class)
            ->except(['index', 'update']);

        Route::post('/tutorials/{tutorial}/regenerate', [TutorialController::class, 'regenerate']);

        // The reading list on a module. Editable, unlike a tutorial - a link
        // and a title are things a human can sensibly correct.
        Route::post('/external-resources', [ExternalResourceController::class, 'store']);
        Route::put('/external-resources/{id}', [ExternalResourceController::class, 'update']);
        Route::delete('/external-resources/{id}', [ExternalResourceController::class, 'destroy']);

        // Feeds the LangGraph AI service its AgentState input.
        Route::get('/roadmap-versions/{version}/ai-export', [RoadmapController::class, 'aiExport']);
    });

    /*
    |----------------------------------------------------------------------
    | Admin and Professor
    |----------------------------------------------------------------------
    */
        /*
    |----------------------------------------------------------------------
    | Professors
    |----------------------------------------------------------------------
    */
    /*
    |----------------------------------------------------------------------
    | Material managers
    |----------------------------------------------------------------------
    */
    Route::middleware('role:MaterialManager')->group(function () {

        // AI proposals: the material manager is the human in the loop.
        Route::get('/ai-workflows', [AiWorkflowController::class, 'index']);
        Route::get('/ai-workflows/{workflow}', [AiWorkflowController::class, 'show']);
        Route::post('/ai-workflows/{workflow}/resume', [AiWorkflowController::class, 'resume']);
        Route::post('/roadmap-versions/{version}/ai-workflows', [AiWorkflowController::class, 'store']);
        Route::post('/ai-workflows/{workflow}/commit', [AiWorkflowController::class, 'commit']);
        Route::get('/learning-runs', [LearningWorkflowController::class, 'index']);
        Route::get('/learning-runs/{run}', [LearningWorkflowController::class, 'show']);
        Route::post('/learning-runs/{run}/resume', [LearningWorkflowController::class, 'resume']);
        Route::post('/learning-runs/{run}/commit', [LearningWorkflowController::class, 'commit']);
        Route::post('/version-nodes/{versionNode}/learning-runs', [LearningWorkflowController::class, 'store']);

        // Material that reached the database and still needs a decision.
        Route::prefix('material')->group(function () {
            Route::get('/stats', [MaterialController::class, 'stats']);
            Route::get('/pending', [MaterialController::class, 'pending']);
            Route::get('/history', [MaterialController::class, 'history']);
            Route::post('/{type}/{id}/approve', [MaterialController::class, 'approve']);
            Route::post('/{type}/{id}/reject', [MaterialController::class, 'reject']);
        });
    });

    Route::middleware('role:Professor')->prefix('professor')->group(function () {
        Route::get('/review-requests', [ProfessorController::class, 'reviewRequests']);
        Route::post('/review-requests/{reviewRequest}/claim', [ProfessorController::class, 'claim']);
        Route::post('/review-requests/{reviewRequest}/review', [ProfessorController::class, 'review']);
        Route::get('/stats', [ProfessorController::class, 'stats']);
    });
});