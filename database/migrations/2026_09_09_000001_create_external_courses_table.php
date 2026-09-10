<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * External courses are taught to outside cohorts using SchedU rooms,
     * staff, and grid without belonging to a local academic list, study plan,
     * or the course catalogue. They are deliberately selected per generation
     * run — never silently included.
     *
     * Components are independent: lab_groups and lecture_groups each default
     * to 0 and at least one must be positive (validated in the form request).
     * The students-per-group column of a component is filled exactly when
     * that component exists. lecture_venue (ours|external) is filled exactly
     * when the lecture component exists; "external" means SchedU reserves the
     * lecture's time and lecturer but no room — the hall belongs to the
     * outside faculty.
     */
    public function up(): void
    {
        Schema::create('external_courses', function (Blueprint $table) {
            $table->id();
            $table->string('code')->nullable();
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('requesting_entity_en');
            $table->string('requesting_entity_ar');
            $table->unsignedTinyInteger('lab_groups')->default(0);
            $table->unsignedSmallInteger('lab_students_per_group')->nullable();
            $table->unsignedTinyInteger('lecture_groups')->default(0);
            $table->unsignedSmallInteger('lecture_students_per_group')->nullable();
            $table->enum('lecture_venue', ['ours', 'external'])->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_courses');
    }
};
