<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('external_resources', function (Blueprint $table) {
            $table->dropForeign(['roadmap_version_id']);
            $table->dropColumn('roadmap_version_id');
        });

        Schema::table('external_resources', function (Blueprint $table) {
            $table->foreignId('version_node_id')
                ->after('id')
                ->constrained('version_nodes')
                ->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('external_resources', function (Blueprint $table) {
            $table->dropForeign(['version_node_id']);
            $table->dropColumn('version_node_id');
        });

        Schema::table('external_resources', function (Blueprint $table) {
            $table->foreignId('roadmap_version_id')
                ->after('id')
                ->constrained('roadmap_versions')
                ->onDelete('cascade');
        });
    }
};