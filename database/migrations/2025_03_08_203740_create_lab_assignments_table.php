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
        Schema::create('lab_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('course_assignment_id');
            $table->unsignedBigInteger('lab_id');
            $table->foreign('course_assignment_id')->references('id')->on('course_assignments')->onDelete('cascade');
            $table->foreign('lab_id')->references('id')->on('laps')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lab_assignments');
    }
};
