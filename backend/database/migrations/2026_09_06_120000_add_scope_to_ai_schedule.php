<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * How much the daily scan takes on.
 *
 * It sent every published roadmap to the AI at once, which is what a daily
 * scan is for and exactly wrong for showing somebody how it works: fifteen
 * roadmaps is fifteen runs, minutes of waiting and a review queue nobody can
 * read through in front of an audience.
 *
 * Both settings are optional and mean "no limit" when unset, so an
 * installation that ignores them keeps the behaviour it had.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ai_schedule', function (Blueprint $table) {
            // At most this many roadmaps per run. Null scans all of them.
            $table->unsignedSmallInteger('max_roadmaps')->nullable()->after('run_at');

            // Only these versions. Null scans every published one.
            $table->json('only_version_ids')->nullable()->after('max_roadmaps');
        });
    }

    public function down(): void
    {
        Schema::table('ai_schedule', function (Blueprint $table) {
            $table->dropColumn(['max_roadmaps', 'only_version_ids']);
        });
    }
};
