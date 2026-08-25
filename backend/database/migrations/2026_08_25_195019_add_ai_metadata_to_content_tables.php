<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The AI agents return far more than these tables have columns for - why a
 * tutorial was chosen and what it covers, why a quiz answer is correct, the
 * eight structured sections of a project brief, the per-criterion breakdown
 * behind a grade.
 *
 * All of it was being thrown away at the boundary. One nullable JSON column
 * per table keeps the original alongside the flattened fields the app reads,
 * so nothing is lost and nothing existing changes.
 */
return new class extends Migration
{
    private const TABLES = [
        'tutorials',
        'quizzes',
        'quiz_questions',
        'projects',
        'external_resources',
        'submissions',
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->json('ai_metadata')->nullable();
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropColumn('ai_metadata');
            });
        }
    }
};
