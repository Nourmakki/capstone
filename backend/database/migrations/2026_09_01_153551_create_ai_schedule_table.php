<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Whether the daily trend scan runs, and when.
 *
 * One row. A table rather than a config value because this is switched from
 * the app by whoever is running it, not from a deploy - the point is being
 * able to turn it off before a demo without editing a file on the server.
 *
 * It starts OFF. A scheduler that begins firing the moment it is deployed is
 * exactly what nobody wants the day they install it, and spending API quota
 * unattended should be something someone chose.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ai_schedule', function (Blueprint $table) {
            $table->id();

            $table->boolean('enabled')->default(false);

            // Local time, as "HH:MM". Stored rather than hard-coded so the run
            // can be moved out of the way of a demo without a deploy.
            $table->string('run_at', 5)->default('07:00');

            // What happened last time, so the switch is not the only thing on
            // the screen and a silent scheduler can be told from a broken one.
            $table->timestamp('last_run_at')->nullable();
            $table->string('last_run_summary')->nullable();

            $table->foreignId('updated_by')
                ->nullable()
                ->constrained('users')
                ->nullOnDelete();

            $table->timestamps();
        });

        // The single row this table exists to hold.
        DB::table('ai_schedule')->insert([
            'enabled'    => false,
            'run_at'     => '07:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('ai_schedule');
    }
};
