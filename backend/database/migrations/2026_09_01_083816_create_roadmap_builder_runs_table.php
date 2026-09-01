<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Runs of the AI system's roadmap *builder*.
 *
 * Distinct from roadmap_ai_workflows, which tracks the workflow that extends
 * an existing version and is therefore keyed to one. The builder starts from
 * a topic string and no roadmap at all - there is nothing to key it to until
 * it has been committed, which is exactly what makes it able to create a
 * roadmap where the other workflow cannot.
 *
 * A thin pointer, like the other two: LangGraph's Postgres checkpoint is the
 * source of truth for the run itself, and this records what we asked for and
 * what came of it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('roadmap_builder_runs', function (Blueprint $table) {
            $table->id();

            // What the manager asked for: "web development".
            $table->string('topic');

            $table->string('thread_id')->index();

            // Her RoadmapBuilderResponseStatus, plus "committed" once we have
            // written the result into our own tables.
            $table->string('status')->default('awaiting_review');

            // scope_review or roadmap_review - her two-stage human review.
            $table->string('review_stage')->nullable();

            // The top-level roadmap this run produced, once committed. Null
            // until then, and nulled rather than cascading if that roadmap is
            // later deleted: the run still happened.
            $table->foreignId('committed_roadmap_id')
                ->nullable()
                ->constrained('roadmaps')
                ->nullOnDelete();

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
        Schema::dropIfExists('roadmap_builder_runs');
    }
};
