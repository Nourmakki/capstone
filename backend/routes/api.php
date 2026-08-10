<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

use App\Http\Controllers\AuthController;
use App\Http\Controllers\TutorialController;
use App\Http\Controllers\RoadmapController;
use App\Http\Controllers\ProgressController;
use App\Http\Controllers\AdminController;

Route::post("/register", [AuthController::class, "register"]);
Route::post("/login", [AuthController::class, "login"]);
Route::post("/logout", [AuthController::class, "logout"]);

Route::get(
    '/version-nodes/{versionNodeId}/tutorials',
    [TutorialController::class, 'index']
);

Route::get(
    '/roadmaps/{roadmap}/latest-version',
    [RoadmapController::class, 'latestVersion']
);

Route::get(
    '/roadmap-versions/{version}/graph',
    [RoadmapController::class, 'graph']
);

Route::get(
    '/roadmaps/{roadmap}/student/{user}',
    [ProgressController::class, 'studentRoadmap']
);

Route::apiResource('tutorials', TutorialController::class);

Route::post(
    '/progress/complete-node',
    [ProgressController::class, 'completeNode']
);

Route::get('/admin/professor-requests', [AdminController::class, 'professorRequests']);
Route::put('/admin/approve-professor/{id}', [AdminController::class, 'approveProfessor']);
Route::delete('/admin/reject-professor/{id}', [AdminController::class, 'rejectProfessor']);
Route::get("/admin/dashboard-stats", [AdminController::class, "dashboardStats"]);