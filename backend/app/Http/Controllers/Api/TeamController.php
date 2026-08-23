<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTeamRequest;
use App\Models\Student;
use App\Models\Team;
use App\Models\TeamMember;
use App\Models\TeamMessage;
use Illuminate\Http\Request;

class TeamController extends Controller
{
    public function index(Request $request)
    {
        // Non-students browse teams read-only; membership flags stay false.
        $student = Student::where('user_id', $request->user()->id)->first();

        $teams = Team::with('students.user')
            ->withCount('students')
            ->orderByDesc('created_at')
            ->get()
            ->map(fn ($t) => $this->payload($t, $student?->id ?? 0));

        return response()->json(['teams' => $teams]);
    }

    public function store(StoreTeamRequest $request)
    {
        $student = $this->studentFor($request);

        $team = Team::create([
            ...$request->validated(),
            'created_by_student_id' => $student->id,
        ]);

        // The creator is automatically the first member.
        TeamMember::create([
            'team_id'    => $team->id,
            'student_id' => $student->id,
        ]);

        $team->load('students.user')->loadCount('students');

        return response()->json(['team' => $this->payload($team, $student->id)], 201);
    }

    public function join(Request $request, Team $team)
    {
        $student = $this->studentFor($request);

        $already = TeamMember::where('team_id', $team->id)
            ->where('student_id', $student->id)
            ->exists();

        if ($already) {
            return response()->json(['message' => 'You are already in this team.'], 409);
        }

        TeamMember::create([
            'team_id'    => $team->id,
            'student_id' => $student->id,
        ]);

        return response()->json(['message' => 'Joined the team.']);
    }

    public function leave(Request $request, Team $team)
    {
        $student = $this->studentFor($request);

        $deleted = TeamMember::where('team_id', $team->id)
            ->where('student_id', $student->id)
            ->delete();

        if (! $deleted) {
            return response()->json(['message' => 'You are not in this team.'], 409);
        }

        return response()->json(['message' => 'Left the team.']);
    }

    public function messages(Request $request, Team $team)
    {
        $this->requireMembership($request, $team);

        $messages = TeamMessage::with('student.user')
            ->where('team_id', $team->id)
            ->orderBy('created_at')
            ->get()
            ->map(fn ($m) => [
                'id'         => $m->id,
                'body'       => $m->body,
                'sender'     => $m->student->user->name ?? 'Unknown',
                'created_at' => $m->created_at,
            ]);

        return response()->json(['messages' => $messages]);
    }

    public function postMessage(Request $request, Team $team)
    {
        $student = $this->requireMembership($request, $team);

        $data = $request->validate([
            'body' => ['required', 'string', 'max:2000'],
        ]);

        $message = TeamMessage::create([
            'team_id'    => $team->id,
            'student_id' => $student->id,
            'body'       => $data['body'],
        ]);

        return response()->json([
            'message' => [
                'id'         => $message->id,
                'body'       => $message->body,
                'sender'     => $request->user()->name,
                'created_at' => $message->created_at,
            ],
        ], 201);
    }

    /**
     * Only members may read or post in a team's chat.
     */
    private function requireMembership(Request $request, Team $team): Student
    {
        $student = $this->studentFor($request);

        $isMember = TeamMember::where('team_id', $team->id)
            ->where('student_id', $student->id)
            ->exists();

        abort_unless($isMember, 403, 'Join this team to see its chat.');

        return $student;
    }

    private function payload(Team $team, int $studentId): array
    {
        return [
            'id'            => $team->id,
            'name'          => $team->name,
            'description'   => $team->description,
            'project_focus' => $team->project_focus,
            'member_count'  => $team->students_count ?? $team->students->count(),
            'members'       => $team->students->map(fn ($s) => $s->user->name ?? 'Unknown')->values(),
            'is_member'     => $team->students->contains('id', $studentId),
            'is_creator'    => $team->created_by_student_id === $studentId,
        ];
    }

    private function studentFor(Request $request): Student
    {
        return Student::where('user_id', $request->user()->id)->firstOrFail();
    }
}