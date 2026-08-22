<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Everything a material manager reviews carries the same four columns,
     * so one queue can list them all and one action can resolve any of them.
     */
    private const TABLES = [
        'roadmaps',
        'roadmap_versions',
        'tutorials',
        'external_resources',
    ];

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

            // Content that already existed was live before reviews existed;
            // leaving it pending would empty the app for every student.
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
