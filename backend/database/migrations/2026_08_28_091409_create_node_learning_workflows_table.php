<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A thin pointer to one run of the AI learning workflow. LangGraph's Postgres
 * checkpoint is the source of truth; this only records which module a run
 * belongs to and whether its material has been committed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('node_learning_workflows', function (Blueprint $table) {
            $table->id();

            $table->foreignId('version_node_id')
                ->constrained('version_nodes')
                ->cascadeOnDelete();

            $table->string('thread_id')->unique();
            $table->string('status');
            $table->string('review_stage')->nullable();
            $table->timestamp('committed_at')->nullable();

            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('node_learning_workflows');
    }
};