<?php

namespace App\Http\Controllers;

use App\Models\User;
use App\Models\Student;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class AuthController extends Controller
{
    public function register(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'email' => 'required|email|unique:users,email',
            'password' => 'required|min:6',
            'location' => 'nullable|string|max:255',
            'role' => 'required|in:student,professor',
        ]);

        $role = $request->role === "student"
            ? "Student"
            : "PendingProfessor";

        $user = User::create([
            'name' => $request->name,
            'email' => $request->email,
            'password' => Hash::make($request->password),
            'role' => $role,
            'location' => $request->location,
        ]);

        if ($role === "Student") {
            Student::create([
                'user_id' => $user->id,
                'level' => 'Beginner',
            ]);
        }

        return response()->json([
            'message' => 'Registration successful.',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'location' => $user->location,
            ],
        ], 201);
    }

    public function login(Request $request)
    {
        $request->validate([
            'email' => 'required|email',
            'password' => 'required',
        ]);

        $user = User::where('email', $request->email)->first();

        if (!$user || !Hash::check($request->password, $user->password)) {
            return response()->json([
                'message' => 'Invalid credentials'
            ], 401);
        }

        if ($user->role === "PendingProfessor") {
            return response()->json([
                'message' => 'Pending approval',
                'pending' => true,
                'user' => [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $user->role,
                    'location' => $user->location,
                ],
            ]);
        }

        return response()->json([
            'message' => 'Login successful',
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'role' => $user->role,
                'location' => $user->location,
            ],
            'pending' => $user->role === "PendingProfessor",
        ]);
    }

    public function logout()
    {
        return response()->json([
            'message' => 'Logged out successfully'
        ]);
    }
}