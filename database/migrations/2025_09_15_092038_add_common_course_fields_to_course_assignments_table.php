<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->unsignedBigInteger('common_study_plan_id')->nullable()->after('is_common');
            $table->unsignedBigInteger('common_course_id')->nullable()->after('common_study_plan_id');

            $table->foreign('common_study_plan_id')->references('id')->on('study_planes')->onDelete('cascade');
            $table->foreign('common_course_id')->references('id')->on('course_assignments')->onDelete('cascade');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('course_assignments', function (Blueprint $table) {
            $table->dropForeign(['common_study_plan_id']);
            $table->dropForeign(['common_course_id']);
            $table->dropColumn(['common_study_plan_id', 'common_course_id']);
        });
    }
};