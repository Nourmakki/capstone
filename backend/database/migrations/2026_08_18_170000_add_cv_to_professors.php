<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('professors', function (Blueprint $table) {
            // Path on the private disk. Nullable so the seeded professor,
            // who never applied, stays valid.
            $table->string('cv_path')->nullable()->after('qualification');

            // The row is now created at signup, before an admin has read
            // anything, so a qualification may not be present yet.
            $table->string('qualification')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('professors', function (Blueprint $table) {
            $table->dropColumn('cv_path');
            $table->string('qualification')->nullable(false)->change();
        });
    }
};
