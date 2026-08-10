<?php

namespace App\Http\Controllers;

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

        $user->role = "Professor";
        $user->save();

        return response()->json([
            "message" => "Professor approved successfully."
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
            "pendingProfessors" => User::where("role", "PendingProfessor")->count(),
            "approvedProfessors" => User::where("role", "Professor")->count(),
            "admins" => User::where("role", "Admin")->count(),
        ]);
    }
}