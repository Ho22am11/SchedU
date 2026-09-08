<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->string('reserved_period_day')->nullable()->after('nameAr');
            $table->time('reserved_period_start_time')->nullable()->after('reserved_period_day');
            $table->time('reserved_period_end_time')->nullable()->after('reserved_period_start_time');
            $table->string('reserved_period_label_ar')->nullable()->after('reserved_period_end_time');
        });
    }

    public function down(): void
    {
        Schema::table('schedules', function (Blueprint $table) {
            $table->dropColumn([
                'reserved_period_day',
                'reserved_period_start_time',
                'reserved_period_end_time',
                'reserved_period_label_ar',
            ]);
        });
    }
};
