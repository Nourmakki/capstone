<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_nodes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_version_id')->constrained('roadmap_versions')->onDelete('cascade');
            $table->foreignId('node_id')->constrained('nodes')->onDelete('cascade');
            $table->unique(['roadmap_version_id', 'node_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_nodes');
    }
};