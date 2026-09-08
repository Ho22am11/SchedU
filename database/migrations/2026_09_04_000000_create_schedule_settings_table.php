<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('schedule_settings', function (Blueprint $table) {
            $table->id();
            $table->string('reserved_day')->nullable();
            $table->time('reserved_start_time')->nullable();
            $table->time('reserved_end_time')->nullable();
            $table->timestamps();
        });

        DB::table('schedule_settings')->insert([
            'id' => 1,
            'reserved_day' => 'tuesday',
            'reserved_start_time' => '13:00:00',
            'reserved_end_time' => '15:00:00',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('schedule_settings');
    }
};
