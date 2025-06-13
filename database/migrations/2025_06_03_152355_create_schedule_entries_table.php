<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{

    public function up(): void
    {
        Schema::create('schedule_entries', function (Blueprint $table) {
            $table->id();

            $table->foreignId('schedule_id')->constrained()->onDelete('cascade');
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->enum('session_type', ['lecture', 'lab']);
            $table->unsignedTinyInteger('group_number');
            $table->unsignedTinyInteger('total_groups');

            $table->foreignId('hall_id')->nullable()->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('lap_id');
            $table->foreign('lap_id')->nullable()->references('id')->on('laps');
            $table->foreignId('lecturer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();

            $table->string('Day');
            $table->time('startTime');
            $table->time('endTime');

            $table->unsignedInteger('student_count');

            $table->foreignId('academic_id')->constrained()->onDelete('cascade');

            $table->unsignedTinyInteger('academic_level');


            $table->timestamps();
        });
    }


    public function down(): void
    {
        Schema::dropIfExists('schedule_entries');
    }
};
