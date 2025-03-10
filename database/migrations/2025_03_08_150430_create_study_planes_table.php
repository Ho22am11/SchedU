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
        Schema::create('study_planes', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedBigInteger('academic_id');
            $table->enum('academicLevel' , [ 1 , 2, 3, 4 ]);
            $table->integer('expected_students');
            $table->foreign('academic_id')->references('id')->on('academics')->onDelete('cascade');
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('study_planes');
    }
};
