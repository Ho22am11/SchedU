<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * External-course data is baked into schedule entries at write time, the
     * same way hall/lab/lecturer names already are: each row snapshots the
     * course's display fields as they were when the schedule was generated,
     * so later edits never rewrite schedule history.
     *
     * The FK on external_course_id is dropped (the column stays, with its
     * index): deleting an external course must stay possible, and old
     * schedules keep rendering from their baked snapshots under the recorded
     * id. The live relation remains only as the legacy fallback for rows
     * written before this migration.
     */
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropForeign(['external_course_id']);
        });

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->string('external_course_code')->nullable()->after('external_course_id');
            $table->string('external_course_name_en')->nullable()->after('external_course_code');
            $table->string('external_course_name_ar')->nullable()->after('external_course_name_en');
            $table->string('external_course_entity_en')->nullable()->after('external_course_name_ar');
            $table->string('external_course_entity_ar')->nullable()->after('external_course_entity_en');
            $table->enum('external_course_venue', ['ours', 'external'])->nullable()->after('external_course_entity_ar');
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropColumn([
                'external_course_code',
                'external_course_name_en',
                'external_course_name_ar',
                'external_course_entity_en',
                'external_course_entity_ar',
                'external_course_venue',
            ]);
        });

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->foreignId('external_course_id')
                ->nullable()
                ->constrained('external_courses')
                ->restrictOnDelete();
        });
    }
};
