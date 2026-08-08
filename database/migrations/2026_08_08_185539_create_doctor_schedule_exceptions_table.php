<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('doctor_schedule_exceptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('doctor_user_id')->constrained('users')->onDelete('cascade');
            $table->date('date');
            $table->time('time'); // e.g., '14:30:00'
            $table->enum('type', ['disabled', 'added']);
            $table->timestamps();

            // A doctor cannot have duplicate overrides for the exact same date and time
            $table->unique(['doctor_user_id', 'date', 'time']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('doctor_schedule_exceptions');
    }
};
