<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit DB-001: rows the code treats as one-per-key are now one-per-key in the
 * database too, so two simultaneous requests can't create a duplicate.
 * (prescriptions.appointment_id is left out until G1 decides one or several.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_availabilities', function (Blueprint $table) {
            $table->unique(['doctor_user_id', 'day_of_week']);
        });

        Schema::table('phone_otps', function (Blueprint $table) {
            $table->dropIndex(['phone']);
            $table->unique('phone');
        });

        // General chats have no appointment (NULL), and NULLs never collide.
        Schema::table('ai_chat_sessions', function (Blueprint $table) {
            $table->unique(['user_id', 'appointment_id']);
        });
    }

    public function down(): void
    {
        // MySQL lets the foreign keys use the new unique indexes and drops their
        // own index, so give each foreign key a plain index back first.
        $needsIndex = ! Schema::hasIndex('ai_chat_sessions', ['user_id']);
        Schema::table('ai_chat_sessions', function (Blueprint $table) use ($needsIndex) {
            if ($needsIndex) {
                $table->index('user_id');
            }
            $table->dropUnique(['user_id', 'appointment_id']);
        });

        Schema::table('phone_otps', function (Blueprint $table) {
            $table->dropUnique(['phone']);
            $table->index('phone');
        });

        $needsIndex = ! Schema::hasIndex('doctor_availabilities', ['doctor_user_id']);
        Schema::table('doctor_availabilities', function (Blueprint $table) use ($needsIndex) {
            if ($needsIndex) {
                $table->index('doctor_user_id');
            }
            $table->dropUnique(['doctor_user_id', 'day_of_week']);
        });
    }
};
