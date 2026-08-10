<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_node_id')->constrained('version_nodes')->onDelete('cascade');
            $table->string('title');
            $table->text('description');
            $table->enum('difficulty', ['beginner', 'intermediate', 'advanced']);
            $table->string('github_url')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};