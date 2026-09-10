<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-staff schedule blockers: day + two-hour slot + a free-text label
     * the engine must never schedule the person into (hard for lecturers
     * and TAs alike) and that renders as a labeled cell on their own
     * exports. TAs are rows in `lecturers`, so one FK covers both roles —
     * same convention as schedule_entries.lecturer_id. Column naming
     * mirrors time_preferences (startTime/endTime as HH:MM strings).
     */
    public function up(): void
    {
        Schema::create('staff_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('lecturer_id')->constrained('lecturers')->cascadeOnDelete();
            $table->string('day');
            $table->string('startTime', 5);
            $table->string('endTime', 5);
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('staff_blockers');
    }
};
