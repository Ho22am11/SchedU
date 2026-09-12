<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Session hours become nullable so an absent component stores null
     * instead of a phantom default. Storing 2 on a component the course does
     * not have made the edit form carry a leftover hours value that the
     * validation rejected — blocking removal of the component itself. The
     * engine already reads absent hours as the historical 2h.
     */
    public function up(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('lab_session_hours')->nullable()->default(2)->change();
            $table->unsignedTinyInteger('lecture_session_hours')->nullable()->default(2)->change();
        });
    }

    public function down(): void
    {
        Schema::table('external_courses', function (Blueprint $table) {
            $table->unsignedTinyInteger('lab_session_hours')->default(2)->change();
            $table->unsignedTinyInteger('lecture_session_hours')->default(2)->change();
        });
    }
};
