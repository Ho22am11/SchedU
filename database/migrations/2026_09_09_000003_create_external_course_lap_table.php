<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Eligible labs for an external course's lab groups — the same inventory
     * laps table course lab assignments draw from. An empty pivot means "no
     * declared labs": the engine falls back to its general lab pool (narrowed
     * by the run's room selection). There is deliberately no hall pivot —
     * lectures in our halls draw from the run's hall pool like any other
     * lecture, and external-venue lectures book no room at all.
     */
    public function up(): void
    {
        Schema::create('external_course_lap', function (Blueprint $table) {
            $table->foreignId('external_course_id')->constrained()->cascadeOnDelete();
            $table->foreignId('lap_id')->constrained('laps')->cascadeOnDelete();

            $table->primary(['external_course_id', 'lap_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('external_course_lap');
    }
};
