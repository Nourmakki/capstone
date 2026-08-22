<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_versions', function (Blueprint $table) {
            // Versions are labelled, not just numbered: "2.1", "2024 refresh".
            // Ordering now uses id (creation order), never this column, since
            // "10" sorts before "2" as a string.
            $table->string('version_number', 20)->change();

            $table->string('title')->nullable()->after('version_number');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_versions', function (Blueprint $table) {
            $table->dropColumn('title');
            $table->integer('version_number')->change();
        });
    }
};
