<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Schedule entries gain an explicit source discriminator so external
     * sessions live beside local course entries without fake course or staff
     * records. Existing rows are all local course entries — entry_kind
     * defaults to "course" for them. lecturer_id and the room columns are
     * already nullable (2026_09_07_000001), which is what roomless
     * external-venue lectures and any future staffless shape store.
     *
     * external_course_id RESTRICTs while schedule history refers to the
     * course: the UI explains the block and editing stays possible.
     */
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->enum('entry_kind', ['course', 'external'])->default('course')->after('session_type');
            $table->foreignId('external_course_id')
                ->nullable()
                ->after('course_ids')
                ->constrained('external_courses')
                ->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropForeign(['external_course_id']);
            $table->dropColumn(['entry_kind', 'external_course_id']);
        });
    }
};
