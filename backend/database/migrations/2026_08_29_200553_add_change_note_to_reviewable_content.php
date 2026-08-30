<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * What was edited, so the review queue can say why something is back in it.
 *
 * Editing approved material sends it back for review, but the queue showed
 * nothing to distinguish it from a brand new item - a manager had to reread a
 * whole quiz to find the one answer that changed.
 *
 * Deliberately not `review_notes`: that column is the reviewer's word on the
 * way out (why it was rejected). This one is the editor's word on the way in.
 * Sharing a column would mean an edit erasing the reason for a past rejection.
 */
return new class extends Migration
{
    private const TABLES = ['quizzes', 'projects', 'tutorials'];

    public function up(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->text('change_note')->nullable()->after('review_notes');
            });
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('change_note');
            });
        }
    }
};
