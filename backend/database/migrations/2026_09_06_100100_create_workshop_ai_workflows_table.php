<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One run of the workshop agent, as the platform sees it.
 *
 * A pointer to her LangGraph thread and what we asked it for. The run's own
 * state - the workshops it found, what a manager approved - lives in her
 * checkpointer; this row is what lets a page list the runs, and what says
 * which roadmap version an approved workshop should be written onto.
 *
 * The same shape as node_learning_workflows and roadmap_builder_runs, because
 * it is the same thing: a review that outlives the request that started it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('workshop_ai_workflows', function (Blueprint $table) {
            $table->id();

            // Where an approved workshop lands. Her workflow is asked for a
            // topic, not a version, so this is the platform's side of the
            // question and has to be recorded when the run starts.
            $table->foreignId('roadmap_version_id')
                ->constrained('roadmap_versions')
                ->cascadeOnDelete();

            // Optional: a workshop can be recommended for one module rather
            // than a whole roadmap, which is how the student page matches it.
            $table->foreignId('version_node_id')
                ->nullable()
                ->constrained('version_nodes')
                ->nullOnDelete();

            $table->string('thread_id')->unique();

            // What was asked for, kept so a run reads as something even when
            // her service is unreachable and the state cannot be fetched.
            $table->string('topic');
            $table->string('student_location')->nullable();

            $table->string('status');
            $table->string('review_stage')->nullable();

            $table->timestamp('committed_at')->nullable();

            $table->foreignId('started_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workshop_ai_workflows');
    }
};
