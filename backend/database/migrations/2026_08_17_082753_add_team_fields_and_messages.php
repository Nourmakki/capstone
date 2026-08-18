<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('teams', function (Blueprint $table) {
            $table->foreignId('created_by_student_id')
                ->nullable()
                ->after('description')
                ->constrained('students')
                ->nullOnDelete();

            // What the team is currently working on, as free text.
            $table->string('project_focus')->nullable()->after('created_by_student_id');
        });

        Schema::create('team_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained('teams')->onDelete('cascade');
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->text('body');
            $table->timestamps();
        });

        Schema::table('team_members', function (Blueprint $table) {
            // A student cannot join the same team twice.
            $table->unique(['team_id', 'student_id']);
        });
    }

    public function down(): void
    {
        Schema::table('team_members', function (Blueprint $table) {
            $table->dropUnique(['team_id', 'student_id']);
        });

        Schema::dropIfExists('team_messages');

        Schema::table('teams', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_student_id');
            $table->dropColumn('project_focus');
        });
    }
};