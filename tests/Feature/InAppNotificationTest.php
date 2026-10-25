<?php

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Services\ReportAnalysisService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Audit L5: in-app notifications (no phone push).

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
});

function noticesFor($user)
{
    return test()->actingAs($user, 'sanctum')->getJson('/api/v1/notifications')->assertOk();
}

function noticeSend($user, int $conversationId, string $body)
{
    return test()->actingAs($user, 'sanctum')
        ->postJson("/api/v1/conversations/{$conversationId}/messages", ['body' => $body])
        ->assertCreated();
}

describe('messages', function () {
    it('tells the patient when a doctor answers their question, once until read', function () {
        $this->doctor = makeDoctor('verified', 'Cardiology');
        $id = $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/conversations', ['subject' => 'Cough', 'department' => 'Cardiology'])
            ->assertCreated()->json('conversation.id');
        noticeSend($this->patient, $id, 'I have a cough');

        expect(noticesFor($this->doctor)->json('meta.unread_count'))->toBe(0);

        noticeSend($this->doctor, $id, 'Drink warm water.');
        noticeSend($this->doctor, $id, 'And rest.');

        noticesFor($this->patient)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'question_answered')
            ->assertJsonPath('data.0.target.conversation_id', $id)
            ->assertJsonPath('meta.unread_count', 1);
    });

    it('tells the other person about a new chat message', function () {
        bookAppointment($this->patient, $this->doctor);
        $id = $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/chats', ['user_id' => $this->doctor->id])->json('conversation.id');

        noticeSend($this->patient, $id, 'Hello doctor');
        noticeSend($this->patient, $id, 'Are you there?');

        noticesFor($this->doctor)
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.kind', 'chat_message')
            ->assertJsonPath('data.0.body', 'Hello doctor');
        noticesFor($this->patient)->assertJsonCount(0, 'data');

        // Once read, the next message makes a new one.
        $noticeId = noticesFor($this->doctor)->json('data.0.id');
        $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/notifications/{$noticeId}/read")->assertOk();
        noticeSend($this->patient, $id, 'Third message');
        noticesFor($this->doctor)->assertJsonCount(2, 'data')->assertJsonPath('meta.unread_count', 1);
    });
});

describe('reports', function () {
    beforeEach(function () {
        Storage::fake('private');
        config(['services.gemini.api_key' => 'test-key']);
        Http::preventStrayRequests();
        $id = $this->actingAs($this->patient, 'sanctum')
            ->post('/api/v1/medical-records', [
                'files' => [UploadedFile::fake()->create('p1.jpg', 20, 'image/jpeg')],
            ], ['Accept' => 'application/json'])
            ->assertCreated()->json('record.id');
        $this->record = MedicalRecord::findOrFail($id);
    });

    it('says when the analysis is ready', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [[
            'text' => json_encode(['title' => 'CBC', 'summary' => 'All values in range.', 'results' => []]),
        ]]]]]])]);

        $this->actingAs($this->patient, 'sanctum')
            ->postJson("/api/v1/medical-records/{$this->record->id}/analyze")->assertStatus(202);

        noticesFor($this->patient)
            ->assertJsonPath('data.0.kind', 'report_ready')
            ->assertJsonPath('data.0.target.record_id', $this->record->id);
    });

    it('says when the analysis failed and the original is kept', function () {
        app(ReportAnalysisService::class)->markFailed($this->record, 'No luck');

        noticesFor($this->patient)
            ->assertJsonPath('data.0.kind', 'report_failed')
            ->assertJsonPath('data.0.body', fn ($body) => str_contains($body, 'original document is kept'));
    });
});

it('tells the patient when the visit summary is shared, not for a draft', function () {
    $visit = bookAppointment($this->patient, $this->doctor);
    $visit->update(['status' => 'completed']);
    $put = fn (array $extra) => $this->actingAs($this->doctor, 'sanctum')
        ->putJson("/api/v1/consultations/{$visit->id}/summary", $extra + ['chief_complaint' => 'Fever']);

    $put([])->assertSuccessful();
    noticesFor($this->patient)->assertJsonCount(0, 'data');

    $put(['publish' => true])->assertSuccessful();
    $put(['advice' => 'Rest'])->assertSuccessful();
    noticesFor($this->patient)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'summary_shared')
        ->assertJsonPath('data.0.target.appointment_id', $visit->id);
});

it('reminds both people once about a visit within the hour', function () {
    $soon = bookAppointment($this->patient, $this->doctor);
    $soon->update(['appointment_datetime' => now()->addMinutes(40)]);
    $later = bookAppointment($this->patient, $this->doctor);
    $later->update(['appointment_datetime' => now()->addHours(3)]);

    $this->artisan('appointments:remind')->assertSuccessful();
    $this->artisan('appointments:remind')->assertSuccessful();

    noticesFor($this->patient)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.kind', 'visit_reminder')
        ->assertJsonPath('data.0.target.for', 'patient');
    noticesFor($this->doctor)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.target.for', 'doctor')
        ->assertJsonPath('data.0.target.patient_user_id', $this->patient->id);
    expect(Appointment::find($later->id)->reminder_sent_at)->toBeNull();
});

it('keeps notifications private and marks all read', function () {
    $visit = bookAppointment($this->patient, $this->doctor);
    $visit->update(['appointment_datetime' => now()->addMinutes(30)]);
    $this->artisan('appointments:remind');
    $noticeId = noticesFor($this->patient)->json('data.0.id');

    $stranger = makePatient();
    $this->actingAs($stranger, 'sanctum')->postJson("/api/v1/notifications/{$noticeId}/read")->assertNotFound();
    noticesFor($stranger)->assertJsonCount(0, 'data');
    $this->getJson('/api/v1/notifications/unread-count')->assertOk()->assertJsonPath('unreadCount', 0);

    $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/notifications/unread-count')->assertJsonPath('unreadCount', 1);
    $this->actingAs($this->patient, 'sanctum')->postJson('/api/v1/notifications/read-all')->assertOk();
    $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/notifications/unread-count')->assertJsonPath('unreadCount', 0);
});
