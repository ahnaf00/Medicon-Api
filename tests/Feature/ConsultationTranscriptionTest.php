<?php

use App\Jobs\FinalizeConsultationTranscriptJob;
use App\Jobs\SummarizeTranscriptJob;
use App\Jobs\TranscribeConsultationJob;
use App\Models\Appointment;
use App\Models\ConsultationAudioChunk;
use App\Models\ConsultationTranscript;
use App\Services\ConsultationTranscriptPipeline;
use App\Services\ConsultationTranscriptionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Sleep;

uses(RefreshDatabase::class);

const TRANSCRIBER_TEST_SECRET = 'transcriber-test-secret-0123456789abcdef';
const CALL_START = '2026-10-05T04:00:00+00:00';
const SESSION_START = '2026-10-05T04:00:01.250000+00:00';

beforeEach(function () {
    config([
        'services.transcriber.secret' => TRANSCRIBER_TEST_SECRET,
        'services.transcriber.consent_grace_ms' => 2000,
        'services.gemini.api_key' => 'test-key',
    ]);
    Storage::fake('private');
    Http::preventStrayRequests();
    Sleep::fake();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo(Carbon::parse(CALL_START));

    $this->patient = makePatient();
    $this->doctor = makeDoctor();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
    $this->appointment->update(['format' => 'video', 'status' => 'in_progress', 'started_at' => now()]);
    $this->transcript = $this->appointment->transcript()->create([]);
    $this->room = "appointment-{$this->appointment->id}";
});

function flacFile(string $name = 'chunk.flac'): UploadedFile
{
    // finfo identifies FLAC by its "fLaC" stream marker.
    return UploadedFile::fake()->createWithContent($name, "fLaC\x00\x00\x00\x22".str_repeat("\x00", 64));
}

function uploadChunk(string $room, array $fields = [], ?string $secret = TRANSCRIBER_TEST_SECRET)
{
    $headers = ['Accept' => 'application/json'];
    if ($secret !== null) {
        $headers['X-Transcriber-Secret'] = $secret;
    }

    return test()->post("/api/v1/internal/transcriber/rooms/{$room}/chunks", $fields + [
        'file' => flacFile(),
        'identity' => 'user-'.test()->doctor->id,
        'started_at_ms' => 0,
        'duration_ms' => 60_000,
        'session_started_at' => SESSION_START,
    ], $headers);
}

function completeRoom(string $room, string $sessionStartedAt = SESSION_START)
{
    return test()->postJson("/api/v1/internal/transcriber/rooms/{$room}/complete", [
        'session_started_at' => $sessionStartedAt,
    ], ['X-Transcriber-Secret' => TRANSCRIBER_TEST_SECRET]);
}

/** Both participants agree at CALL_START. */
function bothConsent(ConsultationTranscript $transcript): void
{
    $transcript->recordConsent('doctor', true);
    $transcript->recordConsent('patient', true);
}

function geminiTranscript(array $segments, string $language = 'en'): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => json_encode([
        'language' => $language,
        'segments' => $segments,
    ])]]]]]];
}

function finishVisit(Appointment $appointment): void
{
    $appointment->update(['status' => 'completed', 'ended_at' => now()]);
}

// --- authentication ---------------------------------------------------------

it('rejects calls without the shared secret', function () {
    bothConsent($this->transcript);

    uploadChunk($this->room, secret: null)->assertUnauthorized();
    uploadChunk($this->room, secret: 'wrong-secret-wrong-secret-wrong-secret')->assertUnauthorized();

    expect(ConsultationAudioChunk::count())->toBe(0);
});

it('disables the endpoints when no strong secret is configured', function () {
    config(['services.transcriber.secret' => 'short']);

    uploadChunk($this->room, secret: 'short')->assertStatus(503);
});

it('does not accept a Sanctum user instead of the secret', function () {
    $this->actingAs($this->doctor, 'sanctum');

    uploadChunk($this->room, secret: null)->assertUnauthorized();
});

// --- chunk upload -----------------------------------------------------------

it('stores a consented chunk on the private disk and starts recording', function () {
    bothConsent($this->transcript);

    $id = uploadChunk($this->room)->assertCreated()->json('id');

    $chunk = ConsultationAudioChunk::findOrFail($id);
    expect($chunk->speaker_role)->toBe('doctor')
        ->and($chunk->user_id)->toBe($this->doctor->id)
        ->and($chunk->session_started_at_ms)->toBe(Carbon::parse(SESSION_START)->getTimestampMs())
        ->and($chunk->file_path)->toStartWith("consultations/{$this->appointment->id}/audio/")
        ->and($this->transcript->fresh()->status)->toBe('recording');

    Storage::disk('private')->assertExists($chunk->file_path);
});

it('treats a retried upload as already stored', function () {
    bothConsent($this->transcript);

    $first = uploadChunk($this->room)->assertCreated()->json('id');
    uploadChunk($this->room)->assertOk()->assertJson(['id' => $first, 'duplicate' => true]);

    expect(ConsultationAudioChunk::count())->toBe(1);
});

it('keeps chunks from separate room sessions apart', function () {
    bothConsent($this->transcript);

    uploadChunk($this->room)->assertCreated();
    // Everyone left and rejoined: the new session restarts at 0 ms.
    uploadChunk($this->room, ['session_started_at' => '2026-10-05T04:10:00+00:00'])->assertCreated();

    expect(ConsultationAudioChunk::count())->toBe(2);
});

it('refuses audio from someone who is not in the consultation', function () {
    bothConsent($this->transcript);
    $stranger = makePatient();

    uploadChunk($this->room, ['identity' => "user-{$stranger->id}"])->assertForbidden();

    expect(ConsultationAudioChunk::count())->toBe(0);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

it('refuses audio when a participant never consented', function () {
    $this->transcript->recordConsent('doctor', true);

    uploadChunk($this->room)->assertForbidden();

    expect(ConsultationAudioChunk::count())->toBe(0);
    expect(Storage::disk('private')->allFiles())->toBe([]);
});

it('checks consent at the time the audio was recorded, not at upload', function () {
    bothConsent($this->transcript);

    // The patient withdraws 60 s into the session; the agent closes the open
    // chunk a moment later and uploads it after the withdrawal.
    $this->travelTo(Carbon::parse(SESSION_START)->addSeconds(60));
    $this->transcript->recordConsent('patient', false);
    $this->travel(5)->seconds();

    uploadChunk($this->room, ['duration_ms' => 60_400])->assertCreated();
});

it('refuses audio recorded after a withdrawal', function () {
    bothConsent($this->transcript);
    $this->travelTo(Carbon::parse(SESSION_START)->addSeconds(60));
    $this->transcript->recordConsent('patient', false);

    // Spans the withdrawal by far more than the grace period.
    uploadChunk($this->room, ['started_at_ms' => 30_000, 'duration_ms' => 60_000])->assertForbidden();
    // Recorded entirely after it.
    uploadChunk($this->room, ['started_at_ms' => 70_000, 'duration_ms' => 10_000])->assertForbidden();

    expect(ConsultationAudioChunk::count())->toBe(0);
});

it('accepts audio recorded after consent is given again', function () {
    bothConsent($this->transcript);
    $this->travelTo(Carbon::parse(SESSION_START)->addSeconds(60));
    $this->transcript->recordConsent('patient', false);
    $this->travelTo(Carbon::parse(SESSION_START)->addSeconds(120));
    $this->transcript->recordConsent('patient', true);

    uploadChunk($this->room, ['started_at_ms' => 120_100, 'duration_ms' => 30_000])->assertCreated();
});

it('returns 404 for rooms that are not video appointments', function () {
    bothConsent($this->transcript);

    uploadChunk('lobby')->assertNotFound();
    uploadChunk('appointment-999999')->assertNotFound();

    $this->appointment->update(['format' => 'in_person']);
    uploadChunk($this->room)->assertNotFound();
});

it('refuses audio once transcription has started', function () {
    bothConsent($this->transcript);
    $this->transcript->update(['status' => 'transcribing']);

    uploadChunk($this->room)->assertStatus(409);
});

it('validates the upload', function (array $fields, string $error) {
    bothConsent($this->transcript);

    uploadChunk($this->room, $fields)->assertUnprocessable()->assertJsonValidationErrors($error);
})->with([
    'not flac' => [['file' => UploadedFile::fake()->create('a.mp3', 10, 'audio/mpeg')], 'file'],
    'too large' => [['file' => UploadedFile::fake()->create('a.flac', 16_000, 'audio/flac')], 'file'],
    'bad identity' => [['identity' => 'agent-1'], 'identity'],
    'negative offset' => [['started_at_ms' => -1], 'started_at_ms'],
    'too long' => [['duration_ms' => 7 * 60 * 1000], 'duration_ms'],
    'no session' => [['session_started_at' => 'yesterday-ish'], 'session_started_at'],
]);

// --- complete / finalise ----------------------------------------------------

it('waits for the visit to end before transcribing', function () {
    Queue::fake();
    bothConsent($this->transcript);
    uploadChunk($this->room)->assertCreated();

    completeRoom($this->room)->assertOk()->assertJson(['status' => 'recording']);

    expect($this->transcript->fresh()->agent_completed_at)->not->toBeNull();
    Queue::assertNotPushed(TranscribeConsultationJob::class);
});

it('queues transcription exactly once when the visit is over', function () {
    Queue::fake();
    bothConsent($this->transcript);
    uploadChunk($this->room)->assertCreated();
    finishVisit($this->appointment);

    completeRoom($this->room)->assertOk()->assertJson(['status' => 'transcribing']);
    completeRoom($this->room)->assertOk();
    (new FinalizeConsultationTranscriptJob($this->transcript))->handle(app(ConsultationTranscriptPipeline::class));

    Queue::assertPushed(TranscribeConsultationJob::class, 1);
});

it('marks the transcript skipped when nobody consented', function () {
    finishVisit($this->appointment);

    completeRoom($this->room)->assertOk()->assertJson(['status' => 'skipped']);

    expect($this->transcript->fresh()->skip_reason)->toBe(ConsultationTranscript::SKIP_NO_CONSENT);
});

it('marks the transcript skipped when both consented but no audio arrived', function () {
    bothConsent($this->transcript);
    finishVisit($this->appointment);

    completeRoom($this->room)->assertOk()->assertJson(['status' => 'skipped']);

    expect($this->transcript->fresh()->skip_reason)->toBe(ConsultationTranscript::SKIP_NO_AUDIO);
});

it('queues a delayed fallback when the doctor ends the call', function () {
    Queue::fake();
    config([
        'services.livekit.http_url' => 'http://livekit.test',
        'services.livekit.api_key' => 'devkey',
        'services.livekit.api_secret' => 'call-test-livekit-secret-0123456789abcd',
        'services.transcriber.finalize_delay_seconds' => 180,
    ]);
    Http::fake(['livekit.test/*' => Http::response([])]);

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson("/api/v1/appointments/{$this->appointment->id}/call/end")
        ->assertOk();

    Queue::assertPushed(FinalizeConsultationTranscriptJob::class, fn ($job) => $job->transcript->is($this->transcript)
        && $job->delay->equalTo(now()->addSeconds(180)));
});

it('transcribes from the fallback when the agent never reported back', function () {
    Queue::fake();
    bothConsent($this->transcript);
    uploadChunk($this->room)->assertCreated();
    finishVisit($this->appointment);

    (new FinalizeConsultationTranscriptJob($this->transcript))->handle(app(ConsultationTranscriptPipeline::class));

    expect($this->transcript->fresh()->status)->toBe('transcribing');
    Queue::assertPushed(TranscribeConsultationJob::class, 1);
});

// --- transcription ----------------------------------------------------------

it('produces interleaved, speaker-labelled segments', function () {
    bothConsent($this->transcript);
    uploadChunk($this->room)->assertCreated();
    uploadChunk($this->room, [
        'identity' => "user-{$this->patient->id}",
        'started_at_ms' => 500,
        'duration_ms' => 59_500,
    ])->assertCreated();
    finishVisit($this->appointment);

    Http::fakeSequence('generativelanguage.googleapis.com/*')
        ->push(geminiTranscript([
            ['start_seconds' => 0.4, 'text' => 'কেমন আছেন? What brings you in today?'],
            ['start_seconds' => 9.0, 'text' => 'How long have you had the cough?'],
            ['start_seconds' => 30.0, 'text' => '  '],
        ], 'mixed'))
        ->push(geminiTranscript([
            ['start_seconds' => 3.0, 'text' => 'আমার তিন দিন ধরে কাশি।'],
            ['start_seconds' => 12.5, 'text' => 'About three days, doctor.'],
        ], 'bn'))
        // The draft summary (task 6.7) follows the transcription.
        ->push(['candidates' => [['content' => ['parts' => [['text' => json_encode([
            'chief_complaint' => 'Cough for three days.',
            'findings' => 'Not discussed',
            'advice' => 'Not discussed',
            'red_flags' => [],
        ])]]]]]]);

    // The queue is sync in tests, so this runs the whole pipeline.
    completeRoom($this->room)->assertOk();

    $transcript = $this->transcript->fresh();
    expect($transcript->status)->toBe('ready')
        ->and($transcript->draft_summary['chief_complaint'])->toBe('Cough for three days.')
        ->and($transcript->language)->toBe('mixed')
        ->and($transcript->transcribed_at)->not->toBeNull()
        ->and($transcript->segments->map(fn ($s) => [$s->speaker_role, $s->start_ms, $s->text])->all())->toBe([
            ['doctor', 400, 'কেমন আছেন? What brings you in today?'],
            ['patient', 3500, 'আমার তিন দিন ধরে কাশি।'],
            ['doctor', 9000, 'How long have you had the cough?'],
            ['patient', 13000, 'About three days, doctor.'],
        ]);

    Http::assertSent(fn (Request $r) => ($r['contents'][0]['parts'][0]['inline_data']['mime_type'] ?? null) === 'audio/flac'
        && $r->header('x-goog-api-key') === ['test-key']);
});

it('marks the transcript failed and keeps the audio when Gemini fails', function () {
    bothConsent($this->transcript);
    $chunkId = uploadChunk($this->room)->assertCreated()->json('id');
    finishVisit($this->appointment);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

    completeRoom($this->room)->assertOk();

    $transcript = $this->transcript->fresh();
    expect($transcript->status)->toBe('failed')
        ->and($transcript->error)->toBe(ConsultationTranscriptionService::GENERIC_ERROR)
        ->and($transcript->segments)->toHaveCount(0);

    Storage::disk('private')->assertExists(ConsultationAudioChunk::findOrFail($chunkId)->file_path);
    // One retry for a server error, then give up.
    Http::assertSentCount(2);
});

it('fails safely when Gemini is not configured', function () {
    config(['services.gemini.api_key' => null]);
    bothConsent($this->transcript);
    uploadChunk($this->room)->assertCreated();
    finishVisit($this->appointment);

    completeRoom($this->room)->assertOk();

    expect($this->transcript->fresh()->status)->toBe('failed');
    expect(ConsultationAudioChunk::count())->toBe(1);
});

it('keeps segments inside the chunk and in the model\'s order', function () {
    Queue::fake([SummarizeTranscriptJob::class]);
    bothConsent($this->transcript);
    uploadChunk($this->room, ['duration_ms' => 10_000])->assertCreated();
    finishVisit($this->appointment);

    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiTranscript([
        ['start_seconds' => 5.0, 'text' => 'first'],
        ['start_seconds' => 2.0, 'text' => 'second'],
        ['start_seconds' => 99.0, 'text' => 'third'],
    ]))]);

    completeRoom($this->room)->assertOk();

    expect($this->transcript->fresh()->segments->map(fn ($s) => [$s->start_ms, $s->text])->all())->toBe([
        [5000, 'first'],
        [5000, 'second'],
        [10000, 'third'],
    ]);
});

// --- consent log ------------------------------------------------------------

it('logs every consent change', function () {
    $this->appointment->update(['status' => 'scheduled', 'started_at' => null]);

    foreach ([true, false, true] as $consent) {
        $this->actingAs($this->patient, 'sanctum')
            ->postJson("/api/v1/appointments/{$this->appointment->id}/call/consent", ['consent' => $consent])
            ->assertOk();
        $this->travel(1)->seconds();
    }

    expect($this->transcript->consentEvents()->orderBy('id')->get()
        ->map(fn ($e) => [$e->role, $e->consent, $e->occurred_at_ms])->all())->toBe([
            ['patient', true, Carbon::parse(CALL_START)->getTimestampMs()],
            ['patient', false, Carbon::parse(CALL_START)->addSecond()->getTimestampMs()],
            ['patient', true, Carbon::parse(CALL_START)->addSeconds(2)->getTimestampMs()],
        ]);
});
