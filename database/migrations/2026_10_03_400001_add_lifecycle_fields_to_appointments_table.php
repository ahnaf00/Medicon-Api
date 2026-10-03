<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('appointments', function (Blueprint $table) {
            $table->enum('status', ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show'])
                ->default('scheduled')
                ->change();
            $table->timestamp('started_at')->nullable()->after('duration_minutes');
            $table->timestamp('ended_at')->nullable()->after('started_at');
        });
    }

    public function down(): void
    {
        DB::table('appointments')->where('status', 'in_progress')->update(['status' => 'scheduled']);

        Schema::table('appointments', function (Blueprint $table) {
            $table->dropColumn(['started_at', 'ended_at']);
            $table->enum('status', ['scheduled', 'completed', 'cancelled', 'no_show'])
                ->default('scheduled')
                ->change();
        });
    }
};
