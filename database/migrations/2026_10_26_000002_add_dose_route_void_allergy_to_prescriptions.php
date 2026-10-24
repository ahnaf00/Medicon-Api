<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit G1 / CLIN-005, the remaining parts:
 * - how much to take at each time of day, and by which route;
 * - cancelling (voiding) a prescription with a reason, and issuing a
 *   replacement for the same visit;
 * - the allergy warning the prescriber saw and confirmed.
 *
 * A voided prescription moves its visit link to `voided_appointment_id`, so the
 * one-per-visit unique index on `appointment_id` only ever covers live ones.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->json('dose_quantities')->nullable()->after('dosage_schedule');
            $table->string('route', 30)->nullable()->after('dose_quantities');
        });

        Schema::table('prescriptions', function (Blueprint $table) {
            $table->timestamp('voided_at')->nullable()->after('status');
            $table->string('void_reason', 500)->nullable()->after('voided_at');
            $table->foreignId('voided_by_user_id')->nullable()->after('void_reason')
                ->constrained('users')->nullOnDelete();
            $table->foreignId('voided_appointment_id')->nullable()->after('voided_by_user_id')
                ->constrained('appointments')->nullOnDelete();
            $table->foreignId('replaces_prescription_id')->nullable()->after('voided_appointment_id')
                ->constrained('prescriptions')->nullOnDelete();
            $table->json('allergy_conflicts')->nullable()->after('replaces_prescription_id');
            $table->timestamp('allergy_acknowledged_at')->nullable()->after('allergy_conflicts');
        });
    }

    public function down(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('replaces_prescription_id');
            $table->dropConstrainedForeignId('voided_appointment_id');
            $table->dropConstrainedForeignId('voided_by_user_id');
            $table->dropColumn(['voided_at', 'void_reason', 'allergy_conflicts', 'allergy_acknowledged_at']);
        });

        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn(['dose_quantities', 'route']);
        });
    }
};
