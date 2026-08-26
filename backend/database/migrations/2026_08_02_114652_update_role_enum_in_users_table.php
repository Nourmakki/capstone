<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
        /**
     * ENUM redefinition is MySQL-specific syntax. SQLite - which the test
     * suite runs on - stores the column as a string with no enum concept,
     * so there is nothing to widen there.
     */
    public function up(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

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

    public function down(): void
    {
        if (DB::getDriverName() !== 'mysql') {
            return;
        }

        DB::statement("
            ALTER TABLE users
            MODIFY role ENUM(
                'Student',
                'Professor'
            ) NOT NULL
        ");
    }
};
