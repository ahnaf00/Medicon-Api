<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('vitals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained('users')->onDelete('cascade');
            $table->string('blood_pressure')->nullable(); // e.g. "120/80"
            $table->integer('pulse_rate')->nullable(); // e.g. 72 bpm
            $table->decimal('glucose_level', 5, 2)->nullable(); // e.g. 5.6 mmol/L
            $table->integer('oxygen_saturation')->nullable(); // e.g. 98%
            $table->dateTime('logged_at')->useCurrent();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('vitals');
    }
};
