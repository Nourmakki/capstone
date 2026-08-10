<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('completed_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_roadmap_progress_id')->constrained('user_roadmap_progress')->onDelete('cascade');
            $table->foreignId('node_id')->constrained('nodes')->onDelete('cascade');
            $table->timestamp('completed_at')->useCurrent();
            $table->unique(['user_roadmap_progress_id', 'node_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('completed_nodes');
    }
};