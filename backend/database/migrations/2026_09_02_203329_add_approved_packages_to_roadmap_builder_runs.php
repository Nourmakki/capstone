<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Keep the roadmaps a manager has already approved during a build.
 *
 * Her builder reviews one roadmap at a time and only reports
 * `approved_roadmap_packages` once the whole run is finished - during the
 * review stages that list comes back empty. So a manager deciding on roadmap
 * three could not see the two they had already accepted, and had no way to
 * judge whether the set held together.
 *
 * Captured on our side as each approval is given.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_builder_runs', function (Blueprint $table) {
            $table->json('approved_packages')->nullable()->after('review_stage');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_builder_runs', function (Blueprint $table) {
            $table->dropColumn('approved_packages');
        });
    }
};
