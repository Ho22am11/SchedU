<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Internal staff distributions on an external course, mirroring the
     * lecturer_assignments shape used by course assignments: rows of
     * (staff, role, num_of_groups). Lecturers staff lectures, TAs staff labs.
     *
     * Absent ta rows means the labs run without internal staff; absent
     * lecturer rows means the lecture is delivered by an external lecturer
     * (allowed only when lecture_venue = "ours"). A present distribution
     * covers every group of its component exactly — the sum rule lives in
     * the form request. Rows cascade away with the course; deleting a staff
     * member cascades like lecturer_assignments, and the DeletionGuard
     * counts these rows so unforced deletions surface the usage first.
     */
    public function up(): void
    {
        Schema::create('external_course_staff', function (Blueprint $table) {
            $table->id();
            $table->foreignId('external_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('staff_id')->constrained('lecturers')->cascadeOnDelete();
            $table->enum('role', ['lecturer', 'ta']);
            $table->unsignedTinyInteger('num_of_groups');
            $table->timestamps();

            $table->unique(['external_course_id', 'staff_id', 'role']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_course_staff');
    }
};
