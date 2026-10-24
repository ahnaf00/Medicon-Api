<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * `expired`: a visit nobody started within its window (audit F10 / API-011).
 * Neither side is blamed, unlike `no_show`, which the doctor sets.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show', 'expired'])
                ->default('scheduled')
                ->change();
        });
    }

    public function down(): void
    {
        DB::table('appointments')->where('status', 'expired')->update(['status' => 'cancelled']);

        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show'])
                ->default('scheduled')
                ->change();
        });
    }
};
