<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Student;

class StudentSeeder extends Seeder
{
    public function run(): void
    {
        Student::firstOrCreate(
            ['user_id' => 1],
            [
                'level' => 'beginner',
            ]
        );
    }
}