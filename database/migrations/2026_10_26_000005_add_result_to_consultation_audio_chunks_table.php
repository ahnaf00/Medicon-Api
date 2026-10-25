<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Each chunk keeps its own transcription result, so a long call is transcribed
 * one chunk per job and a retry redoes only the chunks that failed (audit G8 / REC-003).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->json('result')->nullable()->after('duration_ms');
            $table->timestamp('transcribed_at')->nullable()->after('result');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->dropColumn(['result', 'transcribed_at']);
        });
    }
};
