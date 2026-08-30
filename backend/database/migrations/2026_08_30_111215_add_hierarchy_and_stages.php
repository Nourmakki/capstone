<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The two levels of roadmap structure, in one migration so there is one more
 * thing for Nour to run rather than two.
 *
 * 1. roadmaps.parent_roadmap_id - Frontend, Backend and Full Stack are
 *    roadmaps under Web Development. A parent is a chooser: it has no modules
 *    of its own, only children. Top level is parent_roadmap_id IS NULL.
 *
 *    Modelled as a tree of roadmaps rather than nodes inside one graph
 *    because UserRoadmapProgress is keyed (student, roadmap_version): a
 *    student enrolled in Frontend is counted against Frontend's own modules,
 *    so 100% is reachable. Three specializations as nodes in one graph would
 *    mean picking one leaves the other two permanently incomplete.
 *
 * 2. version_nodes.stage / is_recommended - grouping inside a roadmap.
 *    Foundations, TypeScript, Frameworks are stages holding several modules;
 *    the badge marks the one framework worth starting with.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->foreignId('parent_roadmap_id')
                ->nullable()
                ->after('id')
                // A parent going away must not orphan its children into
                // looking like top-level roadmaps.
                ->constrained('roadmaps')
                ->nullOnDelete();
        });

        Schema::table('version_nodes', function (Blueprint $table) {
            // Null means ungrouped, which is every existing module - a roadmap
            // with no stages still renders as a flat list.
            $table->unsignedSmallInteger('stage')->nullable()->after('node_id');
            $table->string('stage_title')->nullable()->after('stage');
            $table->boolean('is_recommended')->default(false)->after('stage_title');
        });
    }

    public function down(): void
    {
        Schema::table('roadmaps', function (Blueprint $table) {
            $table->dropConstrainedForeignId('parent_roadmap_id');
        });

        Schema::table('version_nodes', function (Blueprint $table) {
            $table->dropColumn(['stage', 'stage_title', 'is_recommended']);
        });
    }
};
