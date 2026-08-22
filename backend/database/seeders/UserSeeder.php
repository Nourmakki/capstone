<?php

namespace Database\Seeders;

use App\Models\Professor;
use App\Models\Student;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    /**
     * Creates one account per role so every part of the app can be
     * demonstrated without registering by hand.
     *
     * All test accounts use the password: password123
     */
    public function run(): void
    {
        $student = User::updateOrCreate(
            ['email' => 'student@capstone.test'],
            [
                'name'     => 'Sara Student',
                'password' => Hash::make('password123'),
                'role'     => 'Student',
                'location' => 'Beirut, Lebanon',
            ]
        );

        Student::updateOrCreate(
            ['user_id' => $student->id],
            ['level' => 'Beginner']
        );

        $professor = User::updateOrCreate(
            ['email' => 'professor@capstone.test'],
            [
                'name'     => 'Paul Professor',
                'password' => Hash::make('password123'),
                'role'     => 'Professor',
                'location' => 'Beirut, Lebanon',
            ]
        );

        Professor::updateOrCreate(
            ['user_id' => $professor->id],
            [
                'qualification' => 'PhD in Computer Science',
                'is_verified'   => true,
            ]
        );

        User::updateOrCreate(
            ['email' => 'admin@capstone.test'],
            [
                'name'     => 'Amal Admin',
                'password' => Hash::make('password123'),
                'role'     => 'Admin',
                'location' => 'Beirut, Lebanon',
            ]
        );

        User::updateOrCreate(
            ['email' => 'material@capstone.test'],
            [
                'name'     => 'Maya Material',
                'password' => Hash::make('password123'),
                'role'     => 'MaterialManager',
                'location' => 'Beirut, Lebanon',
            ]
        );

        // A professor still awaiting approval, so the admin panel has
        // something to review.
        User::updateOrCreate(
            ['email' => 'pending@capstone.test'],
            [
                'name'     => 'Nadia Pending',
                'password' => Hash::make('password123'),
                'role'     => 'PendingProfessor',
                'location' => 'Tripoli, Lebanon',
            ]
        );
    }
}
