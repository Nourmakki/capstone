<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('projects')->nullOnDelete();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('quiz_id')->nullable()->constrained('quizzes')->nullOnDelete();
            $table->longText('content')->nullable();
            $table->enum('status', ['assigned', 'submitted', 'missing', 'ai_reviewed', 'human_reviewed'])->default('assigned');
            $table->unsignedInteger('score')->nullable();
            $table->text('comments')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('submissions');
    }
};