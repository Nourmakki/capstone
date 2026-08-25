<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_ai_workflows', function (Blueprint $table) {
            $table->foreignId('committed_version_id')
                ->nullable()
                ->after('review_stage')
                ->constrained('roadmap_versions')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_ai_workflows', function (Blueprint $table) {
            $table->dropConstrainedForeignId('committed_version_id');
        });
    }
};