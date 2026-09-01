<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Laravel's lock table, which the framework ships and this project never got.
 *
 * CACHE_STORE is `database` and the `cache` table exists, but its companion
 * does not - so anything taking a lock through the cache failed with "table
 * cache_locks doesn't exist". Nothing had ever taken one until the scheduler
 * needed withoutOverlapping().
 *
 * That guard matters here: a scan of every roadmap can outlast the gap to the
 * next tick, and two of them at once would double the API spend and race to
 * record the same runs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache_locks');
    }
};
