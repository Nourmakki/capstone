<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\HumanReviewRequest;
use App\Models\Professor;
use App\Models\Roadmap;
use App\Models\RoadmapVersion;
use App\Models\User;
use Illuminate\Http\Request;

class AdminController extends Controller
{
    public function professorRequests()
    {
        return User::where('role', 'PendingProfessor')
            ->select('id', 'name', 'email', 'location')
            ->get();
    }

    public function approveProfessor($id)
    {
        $user = User::findOrFail($id);

        if ($user->role !== 'PendingProfessor') {
            return response()->json([
                'message' => 'This user is not awaiting professor approval.',
            ], 422);
        }

        $user->role = 'Professor';
        $user->save();

        // Without this row the professor cannot be attached to any review.
        Professor::firstOrCreate(
            ['user_id' => $user->id],
            [
                'qualification' => 'Not specified',
                'is_verified'   => true,
            ]
        );

        return response()->json([
            'message' => 'Professor approved successfully.',
        ]);
    }

    public function rejectProfessor($id)
    {
        $user = User::findOrFail($id);

        $user->delete();

        return response()->json([
            "message" => "Professor request rejected."
        ]);
    }

    public function dashboardStats()
    {
        return response()->json([
            'pendingProfessors'  => User::where('role', 'PendingProfessor')->count(),
            'approvedProfessors' => User::where('role', 'Professor')->count(),
            'admins'             => User::where('role', 'Admin')->count(),
            'students'           => User::where('role', 'Student')->count(),
            'roadmaps'           => Roadmap::count(),
            'publishedVersions'  => RoadmapVersion::where('status', 'Published')->count(),
            'draftVersions'      => RoadmapVersion::where('status', 'Draft')->count(),
            'pendingReviews'     => HumanReviewRequest::where('status', 'pending')->count(),
        ]);
    }
}