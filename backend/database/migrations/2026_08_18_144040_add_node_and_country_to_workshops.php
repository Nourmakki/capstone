<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            // Workshops are recommended by topic, so they attach to a node.
            $table->foreignId('version_node_id')
                ->nullable()
                ->after('roadmap_version_id')
                ->constrained('version_nodes')
                ->nullOnDelete();

            // Matched against the country parsed from the student's location.
            $table->string('country')->nullable()->after('location');
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->dropConstrainedForeignId('version_node_id');
            $table->dropColumn('country');
        });
    }
};