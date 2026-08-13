<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roadmap_ai_workflows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_version_id') ->constrained('roadmap_versions')->cascadeOnDelete();
            $table->uuid('thread_id')->unique();
            $table->string('status');
            $table->string('review_stage')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('roadmap_ai_workflows');
    }
};
