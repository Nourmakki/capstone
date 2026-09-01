<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\CompletedNode;
use App\Models\Student;
use App\Models\Submission;
use App\Models\UserRoadmapProgress;
use App\Models\VersionNode;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        $student = Student::where('user_id', $user->id)->first();

        return response()->json([
            'user' => [
                'id'       => $user->id,
                'name'     => $user->name,
                'email'    => $user->email,
                'role'     => $user->role,
                'location' => $user->location,
                'level'    => $student?->level,
            ],
            'stats'    => $student ? $this->stats($student) : null,
            'roadmaps' => $student ? $this->roadmapProgress($student) : [],
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();

        // Level is deliberately absent. It describes how far a student has
        // actually got, so letting them set it makes it describe nothing -
        // and it is what workshop recommendations and module difficulty are
        // matched against. A student calling themselves Advanced on their
        // first day would be recommended against that claim rather than
        // against their work.
        //
        // Anything sent for it is ignored rather than refused: an older client
        // still posting the whole form should not fail, it should just not
        // move the level.
        $data = $request->validate([
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'email', 'max:255', Rule::unique('users')->ignore($user->id)],
            'location' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        $user->update(array_intersect_key($data, array_flip(['name', 'email', 'location'])));

        return $this->show($request);
    }

    private function stats(Student $student): array
    {
        $progressIds = UserRoadmapProgress::where('student_id', $student->id)->pluck('id');

        return [
            'modules_completed'  => CompletedNode::whereIn('user_roadmap_progress_id', $progressIds)->count(),
            'quizzes_passed'     => Submission::where('student_id', $student->id)
                ->whereNotNull('quiz_id')->where('score', '>=', 70)->count(),
            'projects_submitted' => Submission::where('student_id', $student->id)
                ->whereNotNull('project_id')->count(),
        ];
    }

    private function roadmapProgress(Student $student): array
    {
        return UserRoadmapProgress::with('roadmapVersion.roadmap')
            ->where('student_id', $student->id)
            ->get()
            ->map(function ($progress) {
                $total = VersionNode::where('roadmap_version_id', $progress->roadmap_version_id)->count();
                $done  = CompletedNode::where('user_roadmap_progress_id', $progress->id)->count();

                return [
                    'roadmap_id' => $progress->roadmapVersion->roadmap->id ?? null,
                    'title'      => $progress->roadmapVersion->roadmap->title ?? 'Unknown',
                    'completed'  => $done,
                    'total'      => $total,
                    'percent'    => $total > 0 ? (int) round(($done / $total) * 100) : 0,
                ];
            })->values()->all();
    }
}