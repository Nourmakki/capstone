<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Choice nodes and child roadmaps, matching the AI system's roadmap schema.
 *
 * Her ChoiceRoadmapNode carries a list of RoadmapChoice, each of which is
 * either a topic with its own material or - when separate_roadmap is true - a
 * whole roadmap of its own. Both land here:
 *
 *   parent_version_node_id  the choice belongs to a node rather than the rail
 *   links_to_roadmap_id     separate_roadmap: this choice opens a roadmap
 *   recommendation_reason   her RoadmapChoice.recommendation_reason
 *
 * Choices are version_nodes rather than a table of their own, because a choice
 * needs exactly what a module needs - tutorials, quizzes, projects, resources,
 * progress - and all of that already hangs off version_node_id. A separate
 * table would mean teaching every one of those about a second parent.
 *
 * is_recommended already exists from the stages work, and carries her
 * RoadmapChoice.recommended unchanged.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_nodes', function (Blueprint $table) {
            // A choice hangs off its node. Cascade: deleting the node that
            // offers the choice should not leave the choice orphaned in the
            // rail, where it would render as a module of its own.
            $table->foreignId('parent_version_node_id')
                ->nullable()
                ->after('node_id')
                ->constrained('version_nodes')
                ->cascadeOnDelete();

            // separate_roadmap. nullOnDelete rather than cascade: losing the
            // roadmap should turn the choice back into an ordinary topic, not
            // delete the student's progress on the node offering it.
            $table->foreignId('links_to_roadmap_id')
                ->nullable()
                ->after('parent_version_node_id')
                ->constrained('roadmaps')
                ->nullOnDelete();

            // Her schema requires this whenever recommended is true.
            $table->text('recommendation_reason')->nullable()->after('is_recommended');
        });
    }

    public function down(): void
    {
        Schema::table('version_nodes', function (Blueprint $table) {
            $table->dropForeign(['parent_version_node_id']);
            $table->dropForeign(['links_to_roadmap_id']);
            $table->dropColumn([
                'parent_version_node_id',
                'links_to_roadmap_id',
                'recommendation_reason',
            ]);
        });
    }
};
