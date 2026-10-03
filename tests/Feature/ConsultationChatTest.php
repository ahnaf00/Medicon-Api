<?php

use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use App\Models\ConsultationSummary;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Models\Vital;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

/** A Gemini `streamGenerateContent?alt=sse` body that emits the given fragments. */
function geminiSse(array $fragments): string
{
    return collect($fragments)
        ->map(fn ($text) => 'data: '.json_encode(['candidates' => [['content' => ['role' => 'model', 'parts' => [['text' => $text]]]]]])."\r\n\r\n")
        ->implode('');
}

/** @return array<int, array{event: string, data: array}> */
function sseEvents(string $body): array
{
    preg_match_all('/event: (\w+)\ndata: (.*)\n\n/', $body, $matches, PREG_SET_ORDER);

    return array_map(fn ($m) => ['event' => $m[1], 'data' => json_decode($m[2], true)], $matches);
}

function askConsultation($user, $appointment, string $message = 'What was discussed between us?')
{
    return test()->actingAs($user, 'sanctum')->postJson('/api/v1/ai/consultation-chat', [
        'appointment_id' => $appointment->id,
        'message' => $message,
    ]);
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();

    $this->patient = makePatient(['allergies' => 'Penicillin', 'chronic_conditions' => null]);
    $this->doctor = makeDoctor();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
    $this->appointment->update(['status' => 'completed', 'appointment_datetime' => '2026-10-05 03:00:00']);

    ConsultationSummary::create([
        'appointment_id' => $this->appointment->id,
        'doctor_user_id' => $this->doctor->id,
        'patient_user_id' => $this->patient->id,
        'chief_complaint' => 'Chest pain after running',
        'findings' => 'ECG reviewed, normal sinus rhythm',
        'advice' => 'Aspirin 75 mg daily',
        'red_flags' => ['Chest pain at rest'],
    ]);
});

describe('access', function () {
    it('only serves the appointment\'s patient', function () {
        Http::fake();

        askConsultation(makePatient(), $this->appointment)->assertForbidden();
        askConsultation($this->doctor, $this->appointment)->assertForbidden();
        Http::assertNothingSent();
    });

    it('refuses a consultation without a doctor\'s summary', function () {
        Http::fake();
        ConsultationSummary::query()->delete();

        askConsultation($this->patient, $this->appointment)->assertStatus(409);
        Http::assertNothingSent();
        expect(AiChatMessage::count())->toBe(0);
    });

    it('refuses a consultation that has not been completed', function () {
        Http::fake();
        $this->appointment->update(['status' => 'in_progress']);

        askConsultation($this->patient, $this->appointment)->assertStatus(409);
    });

    it('does not let the general chat continue a consultation session', function () {
        $session = AiChatSession::create(['user_id' => $this->patient->id, 'appointment_id' => $this->appointment->id]);

        $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['message' => 'hi', 'session_id' => $session->id])
            ->assertNotFound();
    });
});

describe('streaming', function () {
    it('streams the reply and stores both turns', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiSse(['You saw Dr. ', 'about chest pain.']))]);

        $response = askConsultation($this->patient, $this->appointment);
        $response->assertOk()->assertHeader('Content-Type', 'text/event-stream; charset=utf-8');

        $events = sseEvents($response->streamedContent());
        $session = AiChatSession::sole();

        expect(array_column($events, 'event'))->toBe(['session', 'delta', 'delta', 'done'])
            ->and($events[0]['data'])->toBe(['sessionId' => $session->id])
            ->and($events[1]['data']['text'])->toBe('You saw Dr. ')
            ->and($session->appointment_id)->toBe($this->appointment->id)
            ->and($session->messages()->oldest('id')->pluck('content', 'role')->all())->toBe([
                'user' => 'What was discussed between us?',
                'assistant' => 'You saw Dr. about chest pain.',
            ]);
    });

    it('grounds the request in this consultation\'s record', function () {
        $prescription = Prescription::factory()->create([
            'appointment_id' => $this->appointment->id,
            'patient_user_id' => $this->patient->id,
            'doctor_user_id' => $this->doctor->id,
        ]);
        PrescriptionItem::factory()->create(['prescription_id' => $prescription->id, 'medicine_name' => 'Ecosprin', 'dosage' => '75mg']);
        Vital::factory()->create(['user_id' => $this->patient->id, 'blood_pressure' => '130/85', 'logged_at' => now()]);
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiSse(['ok']))]);

        askConsultation($this->patient, $this->appointment)->streamedContent();

        Http::assertSent(function (HttpRequest $request) {
            $system = $request['system_instruction']['parts'][0]['text'];

            return str_contains($request->url(), 'streamGenerateContent?alt=sse')
                && str_contains($system, 'Chief complaint: Chest pain after running')
                && str_contains($system, '- Chest pain at rest')
                && str_contains($system, 'Ecosprin 75mg')
                && str_contains($system, 'BP 130/85')
                && str_contains($system, 'Allergies: Penicillin')
                && str_contains($system, 'Chronic conditions: Not recorded')
                && str_contains($system, '5 Oct 2026, 9:00 AM (Dhaka time)')
                && str_contains($system, '999');
        });
    });

    it('sends earlier turns so follow-ups have context', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()
            ->push(geminiSse(['First answer.']))
            ->push(geminiSse(['Second answer.']))]);

        askConsultation($this->patient, $this->appointment, 'First question')->streamedContent();
        askConsultation($this->patient, $this->appointment, 'Second question')->streamedContent();

        expect(AiChatSession::count())->toBe(1);
        Http::assertSent(fn (HttpRequest $r) => array_column(array_column($r['contents'], 'parts'), 0) === [
            ['text' => 'First question'], ['text' => 'First answer.'], ['text' => 'Second question'],
        ]);
    });

    it('reports a model failure as an error event and keeps the question', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['error' => 'boom'], 500)]);

        $events = sseEvents(askConsultation($this->patient, $this->appointment)->streamedContent());

        expect(array_column($events, 'event'))->toBe(['session', 'error'])
            ->and(AiChatMessage::pluck('role')->all())->toBe(['user']);
    });

    it('keeps a partial reply marked as interrupted when the stream breaks', function () {
        $body = geminiSse(['Partial ']).'data: '.json_encode(['error' => ['code' => 503]])."\r\n\r\n";
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response($body)]);

        $events = sseEvents(askConsultation($this->patient, $this->appointment)->streamedContent());

        expect(array_column($events, 'event'))->toBe(['session', 'delta', 'error'])
            ->and(AiChatMessage::where('role', 'assistant')->value('content'))->toBe("Partial \n\n[Response interrupted]");
    });

    it('reports an error when no API key is configured', function () {
        config(['services.gemini.api_key' => null]);
        Http::fake();

        $events = sseEvents(askConsultation($this->patient, $this->appointment)->streamedContent());

        expect(array_column($events, 'event'))->toBe(['session', 'error']);
        Http::assertNothingSent();
    });
});

it('returns the patient\'s chat session with the summary', function () {
    $session = AiChatSession::create(['user_id' => $this->patient->id, 'appointment_id' => $this->appointment->id]);

    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/consultations/{$this->appointment->id}/summary")
        ->assertOk()
        ->assertJsonPath('chatSessionId', $session->id);
});
