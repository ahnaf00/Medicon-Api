<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admins hide a hospital instead of deleting it (owner decision 2026-10-25,
 * audit L2b step 3): a hidden hospital drops out of the patient list but its
 * row, and the audit trail that names it, stay.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('is_24_7')->index();
            $table->timestamp('deactivated_at')->nullable()->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('hospitals', function (Blueprint $table) {
            $table->dropIndex(['is_active']);
            $table->dropColumn(['is_active', 'deactivated_at']);
        });
    }
};
