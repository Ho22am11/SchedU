<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('regulation_imports', function (Blueprint $table) {
            $table->id();
            $table->string('source_system');
            $table->string('source_hash', 64)->unique();
            $table->string('original_filename');
            $table->string('academic_year')->nullable();
            $table->unsignedTinyInteger('term')->nullable();
            $table->string('status')->default('completed');
            $table->json('options')->nullable();
            $table->json('summary');
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('regulation_imports');
    }
};
