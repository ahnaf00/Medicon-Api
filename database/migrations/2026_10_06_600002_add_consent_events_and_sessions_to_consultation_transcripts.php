<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Task 6.6 groundwork.
 *
 * - `consultation_consent_events`: every consent change, in epoch milliseconds,
 *   so an audio chunk can be checked against consent *while it was recorded*
 *   (the `*_consent_at` columns only keep the latest change).
 * - `consultation_audio_chunks.session_started_at_ms`: the transcriber agent's
 *   join time. `started_at_ms` restarts at 0 for each room session, so the
 *   session is part of a chunk's identity and of its absolute time.
 * - `consultation_transcripts.skip_reason` / `agent_completed_at`: why nothing
 *   was transcribed, and when the agent last reported a room closed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('consultation_consent_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_transcript_id')
                ->constrained(indexName: 'consultation_consent_events_transcript_id_foreign')
                ->cascadeOnDelete();
            $table->enum('role', ['doctor', 'patient']);
            $table->boolean('consent');
            $table->unsignedBigInteger('occurred_at_ms');
            $table->timestamps();

            $table->index(['consultation_transcript_id', 'occurred_at_ms'], 'consultation_consent_events_timeline_index');
        });

        // Carry over choices made before the log existed.
        $now = now();
        foreach (DB::table('consultation_transcripts')->get() as $transcript) {
            foreach (['doctor', 'patient'] as $role) {
                if ($transcript->{"{$role}_consent"} === null || $transcript->{"{$role}_consent_at"} === null) {
                    continue;
                }
                DB::table('consultation_consent_events')->insert([
                    'consultation_transcript_id' => $transcript->id,
                    'role' => $role,
                    'consent' => (bool) $transcript->{"{$role}_consent"},
                    'occurred_at_ms' => Carbon::parse($transcript->{"{$role}_consent_at"})->getTimestampMs(),
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }

        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            // Nullable only so the column can be added to an existing table;
            // every upload must send it (ChunkUploadRequest).
            $table->unsignedBigInteger('session_started_at_ms')->nullable()->after('speaker_role');
        });

        // Add the new key before dropping the old one: on MySQL the old index
        // also backs the consultation_transcript_id foreign key.
        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->unique(
                ['consultation_transcript_id', 'user_id', 'session_started_at_ms', 'started_at_ms'],
                'consultation_audio_chunks_session_unique',
            );
        });

        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->dropUnique('consultation_audio_chunks_unique');
        });

        Schema::table('consultation_transcripts', function (Blueprint $table) {
            $table->string('skip_reason')->nullable()->after('error');
            $table->timestamp('agent_completed_at')->nullable()->after('transcribed_at');
        });
    }

    public function down(): void
    {
        Schema::table('consultation_transcripts', function (Blueprint $table) {
            $table->dropColumn(['skip_reason', 'agent_completed_at']);
        });

        // Fails if two sessions stored the same offset; such rows predate the rollback target.
        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->unique(['consultation_transcript_id', 'user_id', 'started_at_ms'], 'consultation_audio_chunks_unique');
        });

        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->dropUnique('consultation_audio_chunks_session_unique');
        });

        Schema::table('consultation_audio_chunks', function (Blueprint $table) {
            $table->dropColumn('session_started_at_ms');
        });

        Schema::dropIfExists('consultation_consent_events');
    }
};
