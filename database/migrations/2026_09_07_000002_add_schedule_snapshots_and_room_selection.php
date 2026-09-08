<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Point-in-time snapshots: schedule entries keep the room/lecturer display
     * names they were created with, and each schedule records which halls and
     * labs were available to its generation run. Renames and deletions can no
     * longer rewrite or break schedule history. No backfill on purpose —
     * readers fall back to the live relations for legacy rows.
     */
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->string('hall_name')->nullable()->after('hall_id');
            $table->string('lap_name')->nullable()->after('lap_id');
            $table->string('lecturer_name')->nullable()->after('lecturer_id');
            $table->string('lecturer_name_ar')->nullable()->after('lecturer_name');
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->json('available_hall_ids')->nullable()->after('source_schedule_id');
            $table->json('available_lab_ids')->nullable()->after('available_hall_ids');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropColumn(['hall_name', 'lap_name', 'lecturer_name', 'lecturer_name_ar']);
        });

        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn(['available_hall_ids', 'available_lab_ids']);
        });
    }
};
