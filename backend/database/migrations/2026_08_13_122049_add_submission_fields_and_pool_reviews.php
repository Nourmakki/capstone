<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // content stays for quiz answers; project work needs its own fields.
            $table->string('github_url')->nullable()->after('content');
            $table->string('file_path')->nullable()->after('github_url');
        });

        Schema::table('human_review_requests', function (Blueprint $table) {
            // A request is created before any professor picks it up.
            $table->foreignId('professor_id')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['github_url', 'file_path']);
        });

        Schema::table('human_review_requests', function (Blueprint $table) {
            $table->foreignId('professor_id')->nullable(false)->change();
        });
    }
};