<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_roadmap_progress', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained('students')->onDelete('cascade');
            $table->foreignId('roadmap_version_id')->constrained('roadmap_versions')->onDelete('cascade');
            $table->foreignId('current_node_id')->nullable()->constrained('nodes')->nullOnDelete();
            $table->enum('status', ['not_started', 'in_progress', 'completed'])->default('not_started');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_roadmap_progress');
    }
};
