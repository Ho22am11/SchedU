<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Deleting a hall used to CASCADE-delete every schedule_entries row
     * referencing it (2025_06_03_152355), wiping schedule history; deleting a
     * lap failed via the RESTRICT FK; deleting a lecturer cascaded entries away.
     * All three references become nullable with ON DELETE SET NULL so room and
     * staff removals never destroy schedule entries.
     */
    public function up(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropForeign(['hall_id']);
            $table->dropForeign(['lap_id']);
            $table->dropForeign(['lecturer_id']);
        });

        // doctrine/dbal is not installed, so nullability changes go through raw
        // DDL. MODIFY restates the full column definition; types must match the
        // referenced id columns exactly for the FKs below.
        DB::statement('ALTER TABLE schedule_entries MODIFY lap_id BIGINT UNSIGNED NULL');
        DB::statement('ALTER TABLE schedule_entries MODIFY lecturer_id BIGINT UNSIGNED NULL');

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->foreign('hall_id')->references('id')->on('halls')->nullOnDelete();
            $table->foreign('lap_id')->references('id')->on('laps')->nullOnDelete();
            $table->foreign('lecturer_id')->references('id')->on('lecturers')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->dropForeign(['hall_id']);
            $table->dropForeign(['lap_id']);
            $table->dropForeign(['lecturer_id']);
        });

        // Intentionally fails loudly if forced deletions already produced NULLs.
        DB::statement('ALTER TABLE schedule_entries MODIFY lap_id BIGINT UNSIGNED NOT NULL');
        DB::statement('ALTER TABLE schedule_entries MODIFY lecturer_id BIGINT UNSIGNED NOT NULL');

        Schema::table('schedule_entries', function (Blueprint $table) {
            $table->foreign('hall_id')->references('id')->on('halls')->cascadeOnDelete();
            $table->foreign('lap_id')->references('id')->on('laps');
            $table->foreign('lecturer_id')->references('id')->on('lecturers')->cascadeOnDelete();
        });
    }
};
