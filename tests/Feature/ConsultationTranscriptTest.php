<?php

use App\Jobs\SummarizeTranscriptJob;
use App\Jobs\TranscribeConsultationJob;
use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptionService;
use App\Services\TranscriptSummaryService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

beforeEach(function () {
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();
    Sleep::fake();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
    $this->appointment->update(['format' => 'video', 'status' => 'completed', 'started_at' => now()->subMinutes(15), 'ended_at' => now()]);

    $this->transcript = $this->appointment->transcript()->create([
        'status' => 'summarizing',
        'language' => 'mixed',
        'transcribed_at' => now(),
    ]);
    $this->transcript->segments()->createMany([
        ['speaker_role' => 'doctor', 'start_ms' => 400, 'text' => 'What brings you in today?', 'order' => 0],
        ['speaker_role' => 'patient', 'start_ms' => 3500, 'text' => 'আমার তিন দিন ধরে কাশি।', 'order' => 1],
        ['speaker_role' => 'doctor', 'start_ms' => 75_000, 'text' => 'Come back at once if you feel short of breath.', 'order' => 2],
    ]);
});

function geminiSummary(array $fields): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => json_encode($fields)]]]]]];
}

function summarize(ConsultationTranscript $transcript): void
{
    (new SummarizeTranscriptJob($transcript))->handle(app(TranscriptSummaryService::class));
}

function transcriptUrl($appointment, string $suffix = ''): string
{
    return "/api/v1/consultations/{$appointment->id}/transcript{$suffix}";
}

function saveSummary($doctor, $appointment, array $body)
{
    return test()->actingAs($doctor, 'sanctum')->putJson("/api/v1/consultations/{$appointment->id}/summary", $body + [
        'chief_complaint' => 'Cough for three days.',
    ]);
}

// --- draft summary ----------------------------------------------------------

it('drafts a summary from the speaker-labelled transcript', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiSummary([
        'chief_complaint' => 'Cough for three days.',
        'findings' => '',
        'advice' => 'Rest and drink fluids.',
        'red_flags' => ['Shortness of breath', ' ', 'Not discussed', 'Shortness of breath'],
    ]))]);

    summarize($this->transcript);

    $transcript = $this->transcript->fresh();
    expect($transcript->status)->toBe('ready')
        ->and($transcript->draft_summary)->toBe([
            'chief_complaint' => 'Cough for three days.',
            'findings' => TranscriptSummaryService::NOT_DISCUSSED,
            'advice' => 'Rest and drink fluids.',
            'red_flags' => ['Shortness of breath'],
        ]);

    Http::assertSent(fn (Request $r) => str_contains(
        $r['contents'][0]['parts'][0]['text'],
        "[00:00] Doctor: What brings you in today?\n[00:03] Patient: আমার তিন দিন ধরে কাশি।\n[01:15] Doctor: Come back at once",
    ));
});

it('marks the transcript failed but keeps it when the summary fails', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([], 500)]);

    summarize($this->transcript);

    $transcript = $this->transcript->fresh();
    expect($transcript->status)->toBe('failed')
        ->and($transcript->error)->toBe(TranscriptSummaryService::GENERIC_ERROR)
        ->and($transcript->segments)->toHaveCount(3)
        ->and($transcript->draft_summary)->toBeNull();
});

it('does not summarise a transcript that is not waiting for it', function () {
    $this->transcript->update(['status' => 'ready']);

    summarize($this->transcript);

    Http::assertNothingSent();
});

it('skips the summary when nothing was said', function () {
    Queue::fake([SummarizeTranscriptJob::class]);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'language' => 'en', 'segments' => [],
    ])]]]]]])]);
    Storage::fake('private');
    Storage::disk('private')->put('a.flac', 'fLaC');
    $this->transcript->segments()->delete();
    $this->transcript->update(['status' => 'transcribing', 'transcribed_at' => null]);
    $this->transcript->audioChunks()->create([
        'user_id' => $this->doctor->id, 'speaker_role' => 'doctor', 'session_started_at_ms' => 0,
        'file_path' => 'a.flac', 'started_at_ms' => 0, 'duration_ms' => 1000,
    ]);

    (new TranscribeConsultationJob($this->transcript))->handle(app(ConsultationTranscriptionService::class));

    expect($this->transcript->fresh()->status)->toBe('ready')
        ->and($this->transcript->fresh()->draft_summary)->toBeNull();
    Queue::assertNotPushed(SummarizeTranscriptJob::class);
});

// --- show -------------------------------------------------------------------

it('shows the doctor the transcript with the AI draft', function () {
    $this->transcript->update(['status' => 'ready', 'draft_summary' => [
        'chief_complaint' => 'Cough.', 'findings' => 'Not discussed', 'advice' => 'Rest.', 'red_flags' => [],
    ]]);

    $this->actingAs($this->doctor, 'sanctum')->getJson(transcriptUrl($this->appointment))
        ->assertOk()
        ->assertJsonPath('data.status', 'ready')
        ->assertJsonPath('data.language', 'mixed')
        ->assertJsonPath('data.draftSummary.chiefComplaint', 'Cough.')
        ->assertJsonPath('data.segments.1', ['speakerRole' => 'patient', 'startMs' => 3500, 'text' => 'আমার তিন দিন ধরে কাশি।']);
});

it('hides the transcript from the patient until the doctor saves a transcript-based summary', function () {
    $this->transcript->update(['status' => 'ready', 'draft_summary' => ['chief_complaint' => 'Cough.']]);

    $this->actingAs($this->patient, 'sanctum')->getJson(transcriptUrl($this->appointment))->assertForbidden();

    saveSummary($this->doctor, $this->appointment, ['source' => 'transcript'])->assertCreated();

    $this->actingAs($this->patient, 'sanctum')->getJson(transcriptUrl($this->appointment))
        ->assertOk()
        ->assertJsonCount(3, 'data.segments')
        ->assertJsonMissingPath('data.draftSummary');
});

it('does not show the transcript to anyone else', function () {
    $this->actingAs(makePatient(), 'sanctum')->getJson(transcriptUrl($this->appointment))->assertForbidden();
    $this->actingAs(makeDoctor(), 'sanctum')->getJson(transcriptUrl($this->appointment))->assertForbidden();
});

it('explains why a call was not recorded', function () {
    $this->transcript->segments()->delete();
    $this->transcript->update(['status' => 'skipped', 'skip_reason' => ConsultationTranscript::SKIP_NO_CONSENT]);

    $this->actingAs($this->doctor, 'sanctum')->getJson(transcriptUrl($this->appointment))
        ->assertOk()
        ->assertJsonPath('data.status', 'skipped')
        ->assertJsonPath('data.skipReason', 'no_consent');
});

it('returns 404 when the consultation has no transcript', function () {
    $this->transcript->delete();

    $this->actingAs($this->doctor, 'sanctum')->getJson(transcriptUrl($this->appointment))->assertNotFound();
});

// --- retry ------------------------------------------------------------------

it('retries only the summary when transcription had finished', function () {
    Queue::fake();
    $this->transcript->update(['status' => 'failed', 'error' => TranscriptSummaryService::GENERIC_ERROR]);

    $this->actingAs($this->doctor, 'sanctum')->postJson(transcriptUrl($this->appointment, '/retry'))
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'summarizing')
        ->assertJsonPath('data.error', null);

    Queue::assertPushed(SummarizeTranscriptJob::class, 1);
    Queue::assertNotPushed(TranscribeConsultationJob::class);
});

it('retries the transcription from the kept audio when it had failed', function () {
    Queue::fake();
    $this->transcript->segments()->delete();
    $this->transcript->update(['status' => 'failed', 'transcribed_at' => null]);
    $this->transcript->audioChunks()->create([
        'user_id' => $this->doctor->id, 'speaker_role' => 'doctor', 'session_started_at_ms' => 0,
        'file_path' => 'consultations/x/audio/a.flac', 'started_at_ms' => 0, 'duration_ms' => 1000,
    ]);

    $this->actingAs($this->doctor, 'sanctum')->postJson(transcriptUrl($this->appointment, '/retry'))
        ->assertStatus(202)
        ->assertJsonPath('data.status', 'transcribing');

    Queue::assertPushed(TranscribeConsultationJob::class, 1);
});

it('only retries a failed transcript', function () {
    $this->transcript->update(['status' => 'ready']);

    $this->actingAs($this->doctor, 'sanctum')->postJson(transcriptUrl($this->appointment, '/retry'))->assertStatus(409);
});

it('only lets the appointment\'s doctor retry', function () {
    $this->transcript->update(['status' => 'failed']);

    $this->actingAs($this->patient, 'sanctum')->postJson(transcriptUrl($this->appointment, '/retry'))->assertForbidden();
    $this->actingAs(makeDoctor(), 'sanctum')->postJson(transcriptUrl($this->appointment, '/retry'))->assertForbidden();

    expect($this->transcript->fresh()->status)->toBe('failed');
});

// --- summary source ---------------------------------------------------------

it('rejects a transcript source without a ready transcript', function () {
    saveSummary($this->doctor, $this->appointment, ['source' => 'transcript'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');

    expect($this->appointment->consultationSummary()->exists())->toBeFalse();
});

it('records a transcript source and keeps it through later edits', function () {
    $this->transcript->update(['status' => 'ready']);

    saveSummary($this->doctor, $this->appointment, ['source' => 'transcript'])
        ->assertCreated()
        ->assertJsonPath('summary.source', 'transcript');

    saveSummary($this->doctor, $this->appointment, ['advice' => 'Rest.'])
        ->assertOk()
        ->assertJsonPath('summary.source', 'transcript');

    saveSummary($this->doctor, $this->appointment, ['source' => 'doctor_note'])
        ->assertOk()
        ->assertJsonPath('summary.source', 'doctor_note');
});

it('validates the summary source', function () {
    saveSummary($this->doctor, $this->appointment, ['source' => 'gemini'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('source');
});
