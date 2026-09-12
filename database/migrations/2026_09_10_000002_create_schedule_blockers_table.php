<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Blockers baked into a schedule: copied from the staff-page templates
     * the generation actually used, plus any added by hand in the editor.
     * Editing a staff member's blockers later never rewrites these — the
     * schedule's past stays as it was generated (same policy as the entry
     * name snapshots and the reserved-period snapshot). Editor-added ones
     * live only here; they never touch the staff template.
     */
    public function up(): void
    {
        Schema::create('schedule_blockers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('schedule_id')->constrained('schedules')->cascadeOnDelete();
            $table->foreignId('lecturer_id')->constrained('lecturers')->cascadeOnDelete();
            $table->string('day');
            $table->string('startTime', 5);
            $table->string('endTime', 5);
            $table->string('label');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_blockers');
    }
};
