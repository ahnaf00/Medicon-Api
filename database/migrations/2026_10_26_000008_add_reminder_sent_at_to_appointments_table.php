<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * When the "visit starts soon" notification went out (audit L5), so each
 * visit is reminded once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->timestamp('reminder_sent_at')->nullable()->after('ended_at');
        });
    }

    public function down(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn('reminder_sent_at');
        });
    }
};
