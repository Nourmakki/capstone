<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A version students may look at but not enter.
 *
 * Committing an AI run writes a whole roadmap of modules that have no
 * material yet. Until now that version was invisible: a subject could be
 * built, approved and sitting there for days with students seeing nothing.
 * Marked announced, it appears as coming soon - the shape is readable, every
 * module is locked, and nothing is enterable until it is published properly.
 *
 * Stored rather than inferred from "is a draft with no material", because
 * that is also true of a draft somebody is halfway through editing by hand -
 * and that one must not reach students. Same reasoning as coming_soon on a
 * module, which is stored for the same reason.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('roadmap_versions', function (Blueprint $table) {
            $table->boolean('announced')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('roadmap_versions', function (Blueprint $table) {
            $table->dropColumn('announced');
        });
    }
};
