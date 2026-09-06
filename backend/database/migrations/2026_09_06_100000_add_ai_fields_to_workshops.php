<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Room for what the workshop agent actually finds.
 *
 * The table was built for workshops somebody typed in: a title, a time, how
 * long it runs. Her agent returns a real listing off the web - who is running
 * it, who is speaking, whether it costs anything - and none of that had a
 * column, so committing one meant throwing most of it away.
 *
 * duration_minutes becomes nullable in the same breath. It was required, and
 * she does not return a duration: a listing says when a thing starts, and
 * often not how long it lasts. Required, it would have to be invented, and an
 * invented number on a page that says "90 minutes" is worse than a blank.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->string('organizer')->nullable()->after('description');
            $table->string('speaker')->nullable()->after('organizer');

            // free / paid / not_specified, her WorkshopCostType. Kept as a
            // string rather than an enum: it is her vocabulary, and an enum
            // would need a migration every time she adds to it.
            $table->string('cost_type')->nullable()->after('url');

            // A string, not a number. She returns what the listing says -
            // "$40", "20 EUR", "Free for students" - and reading a currency
            // out of that to store a decimal would be inventing precision.
            $table->string('price')->nullable()->after('cost_type');

            // What she sent, kept whole, as every other thing the AI writes
            // keeps it. The columns above are what the platform reads; this
            // is what it was read from.
            $table->json('ai_metadata')->nullable()->after('price');
        });

        Schema::table('workshops', function (Blueprint $table) {
            $table->integer('duration_minutes')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('workshops', function (Blueprint $table) {
            $table->dropColumn(['organizer', 'speaker', 'cost_type', 'price', 'ai_metadata']);
        });

        // Anything committed from the AI has no duration, so it cannot go
        // back to being required while those rows exist.
        Schema::table('workshops', function (Blueprint $table) {
            $table->integer('duration_minutes')->default(0)->nullable(false)->change();
        });
    }
};
