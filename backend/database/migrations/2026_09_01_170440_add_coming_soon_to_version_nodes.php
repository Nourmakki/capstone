<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A module announced before its material exists.
 *
 * When the daily trend scan proposes a module and a manager commits it, the
 * new version can go live straight away with the module shown but not yet
 * enterable - the learning workflow is still writing its tutorial, quiz and
 * project. The mark comes off once that material is approved.
 *
 * A stored flag rather than "has no approved material yet", because the two
 * mean different things at publish time. The publish gate refuses a version
 * whose modules are empty, and it should keep refusing - a module nobody
 * filled in is a mistake. A module deliberately announced as coming is not,
 * and this is what tells them apart.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('version_nodes', function (Blueprint $table) {
            $table->boolean('coming_soon')->default(false)->after('is_recommended');
        });
    }

    public function down(): void
    {
        Schema::table('version_nodes', function (Blueprint $table) {
            $table->dropColumn('coming_soon');
        });
    }
};
