<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-course scheduling configuration requested by the outside faculty:
     *
     * - *_session_hours: the span of one group's weekly session, 1 or 2
     *   hours (default 2). 1h sessions of a component may pack two groups
     *   back-to-back into one 2-hour grid slot.
     * - *_time_slots: the allowed 2h grid slots for the component, as
     *   [{day, start_time, end_time}] on the nominal Sun-Thu 09:00-19:00
     *   grid. NULL means the component may be scheduled anywhere — the
     *   historical behaviour. The engine treats a declared set as a hard
     *   restriction and refuses provably infeasible sets before searching.
     */
    public function up(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('lab_session_hours')->default(2);
            $table->unsignedTinyInteger('lecture_session_hours')->default(2);
            $table->json('lab_time_slots')->nullable();
            $table->json('lecture_time_slots')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->dropColumn(['lab_session_hours', 'lecture_session_hours', 'lab_time_slots', 'lecture_time_slots']);
        });
    }
};
