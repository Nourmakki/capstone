<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\ExternalResources;
use App\Models\HumanReviewRequest;
use App\Models\Professor;
use App\Models\Roadmap;
use App\Models\RoadmapAiWorkflow;
use App\Models\RoadmapVersion;
use App\Models\Tutorial;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class AdminController extends Controller
{
    public function professorRequests()
    {
        return User::with('professor')
            ->where('role', 'PendingProfessor')
            ->get()
            ->map(fn ($user) => [
                'id'            => $user->id,
                'name'          => $user->name,
                'email'         => $user->email,
                'location'      => $user->location,
                'qualification' => $user->professor?->qualification,
                'has_cv'        => (bool) $user->professor?->cv_path,
                'applied_at'    => $user->created_at,
            ]);
    }

    /**
     * Streams the applicant's CV inline so the admin can read it before
     * deciding. Served from the private disk - never a public URL.
     */
    public function professorCv($id)
    {
        $user = User::with('professor')->findOrFail($id);
        $path = $user->professor?->cv_path;

        if (! $path || ! Storage::exists($path)) {
            return response()->json(['message' => 'No CV on file for this applicant.'], 404);
        }

        return Storage::response(
            $path,
            Str::slug($user->name) . '-cv.pdf',
            ['Content-Type' => 'application/pdf']
        );
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

        // The row already exists from signup; approving verifies it. The
        // firstOrCreate covers accounts created before CVs were required.
        $professor = Professor::firstOrCreate(
            ['user_id' => $user->id],
            ['qualification' => 'Not specified']
        );

        $professor->update(['is_verified' => true]);

        return response()->json([
            'message' => 'Professor approved successfully.',
        ]);
    }

    public function rejectProfessor($id)
    {
        $user = User::with('professor')->findOrFail($id);

        // Deleting the user cascades the professors row, but the file on
        // disk would be orphaned, so remove it first.
        $path = $user->professor?->cv_path;

        if ($path && Storage::exists($path)) {
            Storage::delete($path);
        }

        $user->delete();

        return response()->json([
            'message' => 'Professor request rejected.',
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

            // Material manager workload.
            'materialManagers'   => User::where('role', 'MaterialManager')->count(),
            'materialPending'    => Roadmap::pendingReview()->count()
                + RoadmapVersion::pendingReview()->count()
                + Tutorial::pendingReview()->count()
                + ExternalResources::pendingReview()->count(),
            'materialRejected'   => Roadmap::where('review_status', 'rejected')->count()
                + RoadmapVersion::where('review_status', 'rejected')->count()
                + Tutorial::where('review_status', 'rejected')->count()
                + ExternalResources::where('review_status', 'rejected')->count(),
            'aiWorkflowsAwaiting' => RoadmapAiWorkflow::where('status', 'awaiting_review')->count(),
        ]);
    }
}