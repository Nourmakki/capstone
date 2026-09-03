<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The queue's own table.
 *
 * This was created as an empty stub - `id` and `timestamps`, nothing else -
 * so `QUEUE_CONNECTION=database` was configured against a table that could
 * not hold a job. Every dispatch failed with "Unknown column 'queue'", and
 * because nothing dispatched until now, nobody found out.
 *
 * The columns below are Laravel's own schema for a database queue, and the
 * two indexes are what a worker uses to claim the next job without scanning.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('jobs');

        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });

        // Laravel 11+ also expects these two for batching and unique jobs.
        if (! Schema::hasTable('job_batches')) {
            Schema::create('job_batches', function (Blueprint $table) {
                $table->string('id')->primary();
                $table->string('name');
                $table->integer('total_jobs');
                $table->integer('pending_jobs');
                $table->integer('failed_jobs');
                $table->longText('failed_job_ids');
                $table->mediumText('options')->nullable();
                $table->integer('cancelled_at')->nullable();
                $table->integer('created_at');
                $table->integer('finished_at')->nullable();
            });
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
    }
};
