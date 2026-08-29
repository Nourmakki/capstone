<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tutorial a student has watched and said so.
     *
     * Nothing recorded that a tutorial had been watched, so a module could be
     * finished without opening one - and the module page showed Tutorials as
     * done whenever any existed. Keyed on the student rather than on their
     * roadmap progress because a tutorial already belongs to one version of
     * one module, so there is nothing to disambiguate.
     */
    public function up(): void
    {
        Schema::create('tutorial_completions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('tutorial_id')->constrained('tutorials')->onDelete('cascade');
            $table->timestamp('completed_at')->useCurrent();

            // Marking the same tutorial twice is the same fact, not two.
            $table->unique(['student_id', 'tutorial_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tutorial_completions');
    }
};
