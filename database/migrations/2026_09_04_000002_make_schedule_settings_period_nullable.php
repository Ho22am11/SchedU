<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedule_settings', function (Blueprint $table) {
            $table->string('reserved_day')->nullable()->change();
            $table->time('reserved_start_time')->nullable()->change();
            $table->time('reserved_end_time')->nullable()->change();
        });
    }

    public function down(): void
    {
        Schema::table('schedule_settings', function (Blueprint $table) {
            $table->string('reserved_day')->nullable(false)->change();
            $table->time('reserved_start_time')->nullable(false)->change();
            $table->time('reserved_end_time')->nullable(false)->change();
        });
    }
};
