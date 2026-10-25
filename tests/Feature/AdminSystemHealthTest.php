<?php

use App\Jobs\TranscribeConsultationJob;
use App\Models\AdminAuditLog;
use App\Models\AiUsageEvent;
use App\Models\ConsultationAudioChunk;
use App\Models\ConsultationTranscript;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

// Audit L2b step 3: System health shows what failed and restarts it, never its content.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
});

function failedJobRow(string $payloadSecret = 'PRIVATE-PATIENT-DATA'): string
{
    $uuid = (string) Str::uuid();
    DB::table('failed_jobs')->insert([
        'uuid' => $uuid,
        'connection' => 'database',
        'queue' => 'default',
        'payload' => json_encode([
            'uuid' => $uuid,
            'displayName' => 'App\\Jobs\\AnalyzeReportJob',
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'data' => ['commandName' => 'App\Jobs\AnalyzeReportJob', 'command' => serialize((object) ['note' => $payloadSecret])],
        ]),
        'exception' => "Illuminate\\Http\\Client\\ConnectionException: cURL error 28 while sending {$payloadSecret} in /var/www/app.php:10\nStack trace:\n#0 ...",
        'failed_at' => '2026-10-25 06:00:00',
    ]);

    return $uuid;
}

it('keeps everyone but admins out of System health', function () {
    $uuid = failedJobRow();

    foreach ([makePatient(), makeDoctor()] as $user) {
        $as = fn () => $this->actingAs($user, 'sanctum');
        $as()->getJson('/api/v1/admin/system/ai-usage')->assertForbidden();
        $as()->getJson('/api/v1/admin/system/transcripts/failed')->assertForbidden();
        $as()->postJson('/api/v1/admin/system/transcripts/1/retry')->assertForbidden();
        $as()->getJson('/api/v1/admin/system/failed-jobs')->assertForbidden();
        $as()->postJson("/api/v1/admin/system/failed-jobs/{$uuid}/retry")->assertForbidden();
        $as()->deleteJson("/api/v1/admin/system/failed-jobs/{$uuid}")->assertForbidden();
    }

    expect(DB::table('failed_jobs')->count())->toBe(1);
});

it('counts AI calls per Dhaka day and per feature, with failures', function () {
    $this->travelTo(Carbon::parse('2026-10-25 06:00', 'UTC'));
    $event = fn (string $utc, string $feature, bool $ok = true, ?int $ms = 1000) => AiUsageEvent::forceCreate([
        'feature' => $feature, 'succeeded' => $ok, 'http_status' => $ok ? 200 : 500,
        'duration_ms' => $ms, 'created_at' => Carbon::parse($utc, 'UTC'),
    ]);
    $event('2026-10-24 18:00', AiUsageEvent::CHAT, true, 1000);           // 25 Oct 00:00 Dhaka
    $event('2026-10-25 05:00', AiUsageEvent::CHAT, false, 3000);
    $event('2026-10-25 05:30', AiUsageEvent::SYMPTOM_CHECK, true, null);
    $event('2026-10-24 17:59', AiUsageEvent::REPORT_ANALYSIS);            // 24 Oct in Dhaka
    $event('2026-10-01 05:00', AiUsageEvent::CHAT);                       // before the 14 days

    $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/system/ai-usage')
        ->assertOk()
        ->assertJsonCount(14, 'days')
        ->assertJsonPath('days.13', ['date' => '2026-10-25', 'total' => 3, 'failed' => 1])
        ->assertJsonPath('days.12', ['date' => '2026-10-24', 'total' => 1, 'failed' => 0])
        ->assertJsonPath('today.total', 3)
        ->assertJsonPath('countingSince', '2026-10-01T05:00:00+00:00')
        ->assertJsonPath('retentionDays', 90);

    expect($res->json('features'))->toBe([
        ['feature' => 'chat', 'total' => 2, 'failed' => 1, 'averageMs' => 2000],
        ['feature' => 'report_analysis', 'total' => 1, 'failed' => 0, 'averageMs' => 1000],
        ['feature' => 'symptom_check', 'total' => 1, 'failed' => 0, 'averageMs' => null],
    ]);
});

it('lists failed transcripts without their text and retries one', function () {
    Queue::fake();
    $visit = bookAppointment(makePatient(), makeDoctor());
    $transcript = ConsultationTranscript::create(['appointment_id' => $visit->id, 'status' => 'failed', 'error' => 'Automatic transcription is not available right now. The recording is kept.']);
    ConsultationAudioChunk::create([
        'consultation_transcript_id' => $transcript->id, 'user_id' => $visit->doctor_user_id,
        'speaker_role' => 'doctor', 'file_path' => 'consultations/test/1.ogg', 'started_at_ms' => 0, 'duration_ms' => 1000,
    ]);
    ConsultationTranscript::create(['appointment_id' => bookAppointment(makePatient(), makeDoctor())->id, 'status' => 'ready']);

    $as = fn () => $this->actingAs($this->admin, 'sanctum');
    $as()->getJson('/api/v1/admin/system/transcripts/failed')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.visitId', $visit->id)
        ->assertJsonPath('data.0.doctor', $visit->doctor->name)
        ->assertJsonPath('data.0.canRetry', true)
        ->assertJsonMissingPath('data.0.draftSummary');

    $as()->postJson("/api/v1/admin/system/transcripts/{$transcript->id}/retry")
        ->assertStatus(202)
        ->assertJsonPath('status', 'transcribing');
    Queue::assertPushed(TranscribeConsultationJob::class);

    $log = AdminAuditLog::sole();
    expect($log->action)->toBe(AdminAuditLog::TRANSCRIPT_RETRIED)
        ->and($log->target_type)->toBe('visit')
        ->and($log->target_id)->toBe($visit->id);

    // No longer failed, so a second retry is refused.
    $as()->postJson("/api/v1/admin/system/transcripts/{$transcript->id}/retry")->assertStatus(409);
});

it('lists failed jobs by name and error type only, never their data', function () {
    $uuid = failedJobRow();

    $res = $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/system/failed-jobs')
        ->assertOk()
        ->assertJsonPath('data.0', [
            'id' => $uuid,
            'job' => 'AnalyzeReportJob',
            'queue' => 'default',
            'error' => 'ConnectionException',
            'failedAt' => '2026-10-25T06:00:00+00:00',
        ]);

    expect($res->getContent())->not->toContain('PRIVATE-PATIENT-DATA')->not->toContain('/var/www');
});

it('retries or discards a failed job and logs it', function () {
    Queue::fake();
    $retry = failedJobRow();
    $discard = failedJobRow();
    $as = fn () => $this->actingAs($this->admin, 'sanctum');

    $as()->postJson("/api/v1/admin/system/failed-jobs/{$retry}/retry")->assertStatus(202);
    $as()->deleteJson("/api/v1/admin/system/failed-jobs/{$discard}")->assertOk();

    expect(DB::table('failed_jobs')->count())->toBe(0)
        ->and(AdminAuditLog::orderBy('id')->get(['action', 'meta'])->toArray())->toBe([
            ['action' => 'job.retried', 'meta' => ['job' => 'AnalyzeReportJob', 'id' => $retry]],
            ['action' => 'job.discarded', 'meta' => ['job' => 'AnalyzeReportJob', 'id' => $discard]],
        ]);

    $as()->postJson("/api/v1/admin/system/failed-jobs/{$retry}/retry")->assertNotFound();

    // A payload the queue cannot read again is refused, and kept so it can be discarded.
    $broken = failedJobRow();
    DB::table('failed_jobs')->where('uuid', $broken)->update(['payload' => json_encode(['displayName' => 'App\Jobs\AnalyzeReportJob', 'data' => ['command' => 'not-a-job']])]);
    $as()->postJson("/api/v1/admin/system/failed-jobs/{$broken}/retry")
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This job cannot be started again. Discard it instead.');
    expect(DB::table('failed_jobs')->where('uuid', $broken)->exists())->toBeTrue();
    $as()->deleteJson('/api/v1/admin/system/failed-jobs/'.Str::uuid())->assertNotFound();
});

it('puts failed jobs and every AI call on the Overview', function () {
    failedJobRow();
    AiUsageEvent::create(['feature' => AiUsageEvent::CALL_SUMMARY, 'succeeded' => true]);

    $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/overview')
        ->assertJsonPath('jobs.failed', 1)
        ->assertJsonPath('ai.requestsToday', 1);
});
