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
      Schema::create('courses', function (Blueprint $table) {
    $table->id();
    $table->string('code')->unique();
    $table->text('name_ar'); 
    $table->text('name_en'); 
    $table->text('practical_components'); 
    $table->integer('lecture_hours');
    $table->integer('practical_hours'); 
    $table->integer('credit_hours');
    $table->softDeletes();
});
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('courses');
    }
};
