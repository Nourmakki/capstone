<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Professor;
use App\Models\Student;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $data = $request->validate([
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['required', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'confirmed', Password::min(8)],
            'location' => ['nullable', 'string', 'max:255'],
            'role'     => ['required', 'in:student,professor'],

            // An admin approves a professor by reading their CV, so it is
            // required for that role. This endpoint is public, hence the
            // strict type and size limits.
            'qualification' => ['required_if:role,professor', 'nullable', 'string', 'max:255'],
            'cv'            => ['required_if:role,professor', 'file', 'mimes:pdf', 'max:5120'],
        ]);

        $role = $data['role'] === 'student' ? 'Student' : 'PendingProfessor';

        $user = User::create([
            'name'     => $data['name'],
            'email'    => $data['email'],
            'password' => Hash::make($data['password']),
            'role'     => $role,
            'location' => $data['location'] ?? null,
        ]);

        if ($role === 'Student') {
            Student::create([
                'user_id' => $user->id,
                'level'   => 'Beginner',
            ]);

            return response()->json([
                'message' => 'Registration successful.',
                'user'    => $this->userPayload($user),
                'token'   => $user->createToken('api')->plainTextToken,
            ], 201);
        }

        // The professors row IS the application: it carries the CV and stays
        // unverified until an admin approves it.
        Professor::create([
            'user_id'       => $user->id,
            'qualification' => $data['qualification'],
            'cv_path'       => $request->file('cv')->store('cvs'),
            'is_verified'   => false,
        ]);

        // Pending professors get no token - they cannot use the app until approved.
        return response()->json([
            'message' => 'Your professor request has been submitted.',
            'user'    => $this->userPayload($user),
            'pending' => true,
        ], 201);
    }

    public function login(Request $request)
    {
        $data = $request->validate([
            'email'    => ['required', 'email'],
            'password' => ['required', 'string'],
        ]);

        $user = User::where('email', $data['email'])->first();

        if (! $user || ! Hash::check($data['password'], $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials.',
            ], 401);
        }

        if ($user->role === 'PendingProfessor') {
            return response()->json([
                'message' => 'Your professor request is still awaiting approval.',
                'user'    => $this->userPayload($user),
                'pending' => true,
            ], 403);
        }

        return response()->json([
            'message' => 'Login successful.',
            'user'    => $this->userPayload($user),
            'token'   => $user->createToken('api')->plainTextToken,
        ]);
    }

    public function logout(Request $request)
    {
        $request->user()->currentAccessToken()->delete();

        return response()->json([
            'message' => 'Logged out successfully.',
        ]);
    }

    public function me(Request $request)
    {
        return response()->json([
            'user' => $this->userPayload($request->user()),
        ]);
    }

    private function userPayload(User $user): array
    {
        return [
            'id'       => $user->id,
            'name'     => $user->name,
            'email'    => $user->email,
            'role'     => $user->role,
            'location' => $user->location,
        ];
    }
}