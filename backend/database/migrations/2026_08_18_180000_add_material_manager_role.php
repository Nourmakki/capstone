<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            ALTER TABLE users
            MODIFY role ENUM(
                'Student',
                'Professor',
                'PendingProfessor',
                'Admin',
                'MaterialManager'
            ) NOT NULL
        ");
    }

    public function down(): void
    {
        // Anyone holding the removed role would violate the narrower enum.
        DB::table('users')->where('role', 'MaterialManager')->update(['role' => 'Admin']);

        DB::statement("
            ALTER TABLE users
            MODIFY role ENUM(
                'Student',
                'Professor',
                'PendingProfessor',
                'Admin'
            ) NOT NULL
        ");
    }
};
