<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('external_resources', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_version_id')->constrained('roadmap_versions')->onDelete('cascade');
            $table->string('title');
            $table->string('url');
            $table->enum('type', ['article', 'social_account', 'documentation', 'course']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_resources');
    }
};