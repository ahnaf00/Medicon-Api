<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Account deletion (audit L3). `deleted_at` starts a 30-day grace period in
 * which the person can restore the account; `purged_at` marks when their
 * personal data was removed afterwards. The row itself is kept so clinical
 * records written by doctors still point at it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->softDeletes();
            $table->timestamp('purged_at')->nullable()->after('deleted_at');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['deleted_at', 'purged_at']);
        });
    }
};
