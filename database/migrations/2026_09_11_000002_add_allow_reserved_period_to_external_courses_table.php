<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-course reserved-period override. When set, THIS course's sessions —
     * and only those, i.e. only the staff assigned to it — may sit in the
     * global reserved period; every other course, lecturer, and lab in the
     * run still avoids it. The engine carries the flag on the course's blocks
     * and exempts exactly those from its reserved-period rules.
     */
    public function up(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->boolean('allow_reserved_period')->default(false)->after('lecture_venue');
        });
    }

    public function down(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->dropColumn('allow_reserved_period');
        });
    }
};
