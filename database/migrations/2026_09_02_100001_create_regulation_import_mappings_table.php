<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_import_mappings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('regulation_import_id')->constrained()->cascadeOnDelete();
            $table->string('source_key')->unique();
            $table->string('entity_type');
            $table->unsignedBigInteger('target_id');
            $table->string('source_checksum', 64)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['entity_type', 'target_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_import_mappings');
    }
};
