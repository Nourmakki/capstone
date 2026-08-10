<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('version_edges', function (Blueprint $table) {
            $table->id();
            $table->foreignId('roadmap_version_id')->constrained('roadmap_versions')->onDelete('cascade');
            $table->foreignId('edge_id')->constrained('edges')->onDelete('cascade');
            $table->unique(['roadmap_version_id', 'edge_id']);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('version_edges');
    }
};