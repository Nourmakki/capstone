<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Quizzes and projects were the two content types the material manager
     * could not gate. The AI will generate both, so they need the same
     * review columns everything else already has.
     */
    private const TABLES = ['quizzes', 'projects'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->enum('review_status', ['pending', 'approved', 'rejected'])
                    ->default('pending');

                $blueprint->foreignId('reviewed_by')
                    ->nullable()
                    ->constrained('users')
                    ->nullOnDelete();

                $blueprint->timestamp('reviewed_at')->nullable();
                $blueprint->text('review_notes')->nullable();
            });

            // Existing content was live before reviews existed; leaving it
            // pending would pull quizzes and projects out from under students.
            DB::table($table)->update(['review_status' => 'approved']);
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $blueprint) {
                $blueprint->dropConstrainedForeignId('reviewed_by');
                $blueprint->dropColumn(['review_status', 'reviewed_at', 'review_notes']);
            });
        }
    }
};
