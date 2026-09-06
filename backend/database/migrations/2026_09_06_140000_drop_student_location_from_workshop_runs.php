<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The workshop search stops asking where the student is.
 *
 * It was a box on the manager's form, and the wrong person to ask. A manager
 * approves what is worth approving; which of those a given student should see
 * is the student page's question, and it already answers it - the For You tab
 * matches a workshop's country against their own, and shows an online one to
 * everybody.
 *
 * Asking here decided for every student at once. A search run near Beirut
 * quietly withheld an online conference from all of them, and there was no
 * way to say "everywhere" except by leaving a box blank and hoping that meant
 * what it looked like.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('workshop_ai_workflows', function (Blueprint $table) {
            $table->dropColumn('student_location');
        });
    }

    public function down(): void
    {
        Schema::table('workshop_ai_workflows', function (Blueprint $table) {
            $table->string('student_location')->nullable()->after('topic');
        });
    }
};
