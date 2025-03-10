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
        Schema::create('lecturer_assignments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('course_assignment_id');
            $table->unsignedBigInteger('lecturer_id'); 
            $table->integer('num_groups')->default(1);
            $table->enum('type', ['lecturer', 'teaching_assistant']);
            $table->timestamps();

            $table->foreign('course_assignment_id')->references('id')->on('course_assignments')->onDelete('cascade');
            $table->foreign('lecturer_id')->references('id')->on('lecturers')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('lecturer_assignments');
    }
};
