<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('lab_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('medical_records')->onDelete('cascade');
            $table->string('panel');
            $table->string('sub_group')->nullable();
            $table->string('name');
            $table->string('value');
            $table->string('unit')->nullable();
            $table->string('reference_text')->nullable();
            $table->decimal('reference_low', 12, 4)->nullable();
            $table->decimal('reference_high', 12, 4)->nullable();
            $table->enum('status', ['low', 'normal', 'high', 'unknown'])->default('unknown');
            $table->unsignedInteger('order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('lab_results');
    }
};
