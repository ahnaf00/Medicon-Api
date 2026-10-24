<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Audit CLIN-006: who wrote a medicine's "Why take this medicine?" text (the AI or
 * the prescribing doctor), and whether the doctor hid it from the patient.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->string('explanation_source', 10)->nullable()->after('explanation'); // ai | doctor
            $table->boolean('explanation_hidden')->default(false)->after('explanation_source');
        });

        // Every explanation so far came from the model.
        DB::table('prescription_items')->whereNotNull('explanation')->update(['explanation_source' => 'ai']);
    }

    public function down(): void
    {
        Schema::table('prescription_items', function (Blueprint $table) {
            $table->dropColumn(['explanation_source', 'explanation_hidden']);
        });
    }
};
