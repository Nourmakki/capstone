<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            // The AI grade is kept separately so a later human review
            // never overwrites it - the student can compare both.
            $table->unsignedInteger('ai_score')->nullable()->after('score');
            $table->text('ai_feedback')->nullable()->after('ai_score');
            $table->timestamp('ai_reviewed_at')->nullable()->after('ai_feedback');
        });
    }

    public function down(): void
    {
        Schema::table('submissions', function (Blueprint $table) {
            $table->dropColumn(['ai_score', 'ai_feedback', 'ai_reviewed_at']);
        });
    }
};