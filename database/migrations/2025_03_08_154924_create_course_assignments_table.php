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
        Schema::create('course_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('study_plan_id');
            $table->unsignedBigInteger('course_id');
            $table->integer('lecture_groups')->default(0);
            $table->integer('lab_groups')->default(0);
            $table->boolean('is_common')->default(false);
            $table->boolean('practical_in_labs')->default(true);
            $table->timestamps();

            $table->foreign('study_plan_id')->references('id')->on('study_planes')->onDelete('cascade');
            $table->foreign('course_id')->references('id')->on('courses')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('course_assignments');
    }
};
