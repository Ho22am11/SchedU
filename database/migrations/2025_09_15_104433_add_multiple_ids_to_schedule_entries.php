<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            // Add new columns for multiple IDs (as JSON)
            $table->json('course_ids')->after('course_id');
            $table->json('academic_ids')->after('academic_id');
            $table->json('academic_levels')->after('academic_level');
            $table->json('department_ids')->after('department_id');
        });

        // Remove old single ID columns
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropForeign(['course_id']);
            $table->dropForeign(['academic_id']);
            $table->dropForeign(['department_id']);

            $table->dropColumn(['course_id', 'academic_id', 'academic_level', 'department_id']);
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            // Restore old columns
            $table->foreignId('course_id')->constrained()->onDelete('cascade');
            $table->foreignId('academic_id')->constrained()->onDelete('cascade');
            $table->unsignedTinyInteger('academic_level');
            $table->foreignId('department_id')->constrained()->cascadeOnDelete();
        });

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropColumn(['course_ids', 'academic_ids', 'academic_levels', 'department_ids']);
        });
    }
};