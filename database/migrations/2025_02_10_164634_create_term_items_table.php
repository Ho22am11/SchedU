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
        Schema::create('term_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('name_ar');
            $table->unsignedBigInteger('academic_id');
            $table->enum('academicLevel' , [ 1 , 2, 3, 4 ]);
            $table->unsignedBigInteger('lecturer_id');
            $table->unsignedBigInteger('spaces_id');
            $table->foreign('academic_id')->references('id')->on('academics')->cascadeOnDelete();
            $table->foreign('lecturer_id')->references('id')->on('lecturers')->cascadeOnDelete();
            $table->foreign('spaces_id')->references('id')->on('acadmic_spaces')->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('term_items');
    }
};
