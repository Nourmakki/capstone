<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('edges', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('from_node_id');
            $table->unsignedBigInteger('to_node_id');
            $table->foreign('from_node_id')->references('id')->on('nodes')->onDelete('cascade');
            $table->foreign('to_node_id')->references('id')->on('nodes')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('edges');
    }
};
