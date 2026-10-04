<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // One per video appointment: both parties' recording consent and the
        // transcription pipeline's state.
        Schema::create('consultation_transcripts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('appointment_id')->unique()->constrained()->cascadeOnDelete();
            $table->boolean('patient_consent')->nullable();
            $table->timestamp('patient_consent_at')->nullable();
            $table->boolean('doctor_consent')->nullable();
            $table->timestamp('doctor_consent_at')->nullable();
            $table->enum('status', [
                'awaiting_call', 'recording', 'transcribing', 'summarizing', 'ready', 'failed', 'skipped',
            ])->default('awaiting_call');
            $table->text('error')->nullable();
            $table->json('draft_summary')->nullable();
            $table->string('language')->nullable();
            $table->timestamp('transcribed_at')->nullable();
            $table->timestamps();
        });

        // Per-speaker FLAC files on the private disk, uploaded by the transcriber agent.
        Schema::create('consultation_audio_chunks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_transcript_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('speaker_role', ['doctor', 'patient']);
            $table->string('file_path');
            $table->unsignedInteger('started_at_ms');
            $table->unsignedInteger('duration_ms');
            $table->timestamps();

            // Makes agent retries idempotent.
            $table->unique(['consultation_transcript_id', 'user_id', 'started_at_ms'], 'consultation_audio_chunks_unique');
        });

        // Explicit index names: the generated ones exceed MySQL's 64-character limit.
        Schema::create('consultation_transcript_segments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('consultation_transcript_id')
                ->constrained(indexName: 'consultation_segments_transcript_id_foreign')
                ->cascadeOnDelete();
            $table->enum('speaker_role', ['doctor', 'patient']);
            $table->unsignedInteger('start_ms');
            $table->text('text');
            $table->unsignedInteger('order');
            $table->timestamps();

            $table->index(['consultation_transcript_id', 'order'], 'consultation_segments_transcript_order_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('consultation_transcript_segments');
        Schema::dropIfExists('consultation_audio_chunks');
        Schema::dropIfExists('consultation_transcripts');
    }
};
