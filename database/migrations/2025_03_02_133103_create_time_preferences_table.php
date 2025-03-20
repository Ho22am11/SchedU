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
        Schema::create('time_preferences', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('timeable_id');
            $table->string('timeable_type');
            $table->string('day');
            $table->string('startTime', 5); 
            $table->string('endTime', 5); 
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('time_preferences');
    }
};
