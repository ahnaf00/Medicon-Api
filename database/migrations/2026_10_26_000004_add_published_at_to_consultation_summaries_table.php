<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * A summary is a draft until the doctor shares it; the patient sees only
 * published ones (audit G7 / REC-004).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_summaries', function (Blueprint $table) {
            $table->timestamp('published_at')->nullable()->after('source');
        });

        // Summaries saved before this change were already shown to patients.
        DB::table('consultation_summaries')->update(['published_at' => DB::raw('updated_at')]);
    }

    public function down(): void
    {
        Schema::table('consultation_summaries', function (Blueprint $table) {
            $table->dropColumn('published_at');
        });
    }
};
