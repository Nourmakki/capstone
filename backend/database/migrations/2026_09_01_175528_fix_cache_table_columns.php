<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Give the cache table the columns the cache actually uses.
 *
 * `create_cache_table` was generated in July and never filled in - it made a
 * table with an id and timestamps, which is what `make:migration` leaves you.
 * CACHE_STORE is `database`, so Laravel has been looking for `key`, `value`
 * and `expiration` in it ever since and finding none of them.
 *
 * Nothing noticed because nothing in the app cached anything, until the
 * scheduler: `schedule:run` reads a paused flag from the cache before it does
 * anything else, so the daily scan failed at the first line with "Unknown
 * column 'key'".
 *
 * Recreated rather than altered. The table is empty by definition - a cache
 * that has never worked is holding nothing - and adding three columns to a
 * table whose primary key is wrong would leave the id behind.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('cache');

        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->integer('expiration');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cache');

        // Back to the stub, so rolling back lands where this started.
        Schema::create('cache', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
