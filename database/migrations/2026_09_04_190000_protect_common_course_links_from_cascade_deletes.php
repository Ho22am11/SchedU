<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->dropForeign(['common_study_plan_id']);
            $table->dropForeign(['common_course_id']);

            $table->foreign('common_study_plan_id')
                ->references('id')
                ->on('study_planes')
                ->restrictOnDelete();
            $table->foreign('common_course_id')
                ->references('id')
                ->on('course_assignments')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->dropForeign(['common_study_plan_id']);
            $table->dropForeign(['common_course_id']);

            $table->foreign('common_study_plan_id')
                ->references('id')
                ->on('study_planes')
                ->cascadeOnDelete();
            $table->foreign('common_course_id')
                ->references('id')
                ->on('course_assignments')
                ->cascadeOnDelete();
        });
    }
};
