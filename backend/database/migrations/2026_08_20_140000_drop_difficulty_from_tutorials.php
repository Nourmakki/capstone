<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * A tutorial's difficulty is the module's difficulty - carrying a second
     * copy only creates a way for the two to disagree.
     */
    public function up(): void
    {
        Schema::table('tutorials', function (Blueprint $table) {
            $table->dropColumn('difficulty');
        });
    }

    public function down(): void
    {
        Schema::table('tutorials', function (Blueprint $table) {
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced'])
                ->default('beginner')
                ->after('source_name');
        });
    }
};
