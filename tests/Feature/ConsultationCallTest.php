<?php

use App\Models\Appointment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

const CALL_SECRET = 'call-test-livekit-secret-0123456789abcd';

beforeEach(function () {
    config([
        'services.livekit.url' => 'ws://192.168.0.10:7880',
        'services.livekit.http_url' => 'http://livekit.test',
        'services.livekit.api_key' => 'devkey',
        'services.livekit.api_secret' => CALL_SECRET,
    ]);
    Http::preventStrayRequests();

    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-10-05 04:00:00');
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
    $this->appointment->update(['format' => 'video']);
});

function callApi($user, Appointment $appointment, string $action, array $body = [])
{
    return test()->actingAs($user, 'sanctum')
        ->postJson("/api/v1/appointments/{$appointment->id}/call/{$action}", $body);
}

function callClaims(string $jwt): array
{
    return json_decode(json_encode(JWT::decode($jwt, new Key(CALL_SECRET, 'HS256'))), true);
}

function fakeLiveKit(int $status = 200, array $body = []): void
{
    Http::fake(['livekit.test/*' => Http::response($body, $status)]);
}

// --- token ------------------------------------------------------------------

it('lets the doctor start the call, moving the visit to in progress', function () {
    $response = callApi($this->doctor, $this->appointment, 'token')
        ->assertOk()
        ->assertJsonPath('url', 'ws://192.168.0.10:7880')
        ->assertJsonPath('room', "appointment-{$this->appointment->id}");

    $claims = callClaims($response->json('token'));
    expect($claims['sub'])->toBe("user-{$this->doctor->id}")
        ->and($claims['video']['room'])->toBe("appointment-{$this->appointment->id}")
        ->and($claims['attributes'])->toBe(['role' => 'doctor', 'consent' => 'false']);

    $appointment = $this->appointment->fresh();
    expect($appointment->status)->toBe('in_progress')
        ->and($appointment->started_at->toIso8601String())->toBe('2026-10-05T04:00:00+00:00')
        ->and($appointment->transcript->status)->toBe('awaiting_call');
});

it('does not let the patient join before the doctor starts', function () {
    callApi($this->patient, $this->appointment, 'token')
        ->assertStatus(409)
        ->assertJsonPath('message', 'Your doctor has not started this consultation yet.');

    expect($this->appointment->fresh()->status)->toBe('scheduled');
});

it('lets the patient join once the call is in progress', function () {
    callApi($this->doctor, $this->appointment, 'token')->assertOk();

    $claims = callClaims(callApi($this->patient, $this->appointment, 'token')->assertOk()->json('token'));

    expect($claims['sub'])->toBe("user-{$this->patient->id}")
        ->and($claims['attributes'])->toBe(['role' => 'patient', 'consent' => 'false']);
});

it('lets the doctor rejoin without restarting the visit', function () {
    callApi($this->doctor, $this->appointment, 'token')->assertOk();
    $this->travel(5)->minutes();

    callApi($this->doctor, $this->appointment, 'token')->assertOk();

    expect($this->appointment->fresh()->started_at->toIso8601String())->toBe('2026-10-05T04:00:00+00:00');
});

it('puts the stored consent into the token', function () {
    callApi($this->doctor, $this->appointment, 'consent', ['consent' => true])->assertOk();

    $claims = callClaims(callApi($this->doctor, $this->appointment, 'token')->json('token'));

    expect($claims['attributes']['consent'])->toBe('true');
});

it('refuses tokens once the visit is over', function (string $status) {
    $this->appointment->update(['status' => $status]);

    callApi($this->doctor, $this->appointment, 'token')->assertStatus(409);
    callApi($this->patient, $this->appointment, 'token')->assertStatus(409);
})->with(['completed', 'cancelled', 'no_show']);

it('does not let an unverified doctor start a call', function () {
    $pending = makeDoctor('pending');
    $appointment = bookAppointment($this->patient, $pending);
    $appointment->update(['format' => 'video']);

    callApi($pending, $appointment, 'token')->assertForbidden();

    expect($appointment->fresh()->status)->toBe('scheduled');
});

it('does not start the visit when LiveKit is not configured', function () {
    config(['services.livekit.api_secret' => null]);

    callApi($this->doctor, $this->appointment, 'token')->assertStatus(503);

    expect($this->appointment->fresh()->status)->toBe('scheduled');
});

// --- access -----------------------------------------------------------------

it('forbids strangers from every call endpoint', function () {
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);
    $otherPatient = makePatient();
    $otherDoctor = makeDoctor();

    foreach ([$otherPatient, $otherDoctor] as $stranger) {
        callApi($stranger, $this->appointment, 'token')->assertForbidden();
        callApi($stranger, $this->appointment, 'consent', ['consent' => true])->assertForbidden();
    }
    callApi($otherDoctor, $this->appointment, 'end')->assertForbidden();

    expect($this->appointment->fresh()->transcript)->toBeNull();
    Http::assertNothingSent();
});

it('returns 422 for an in-person appointment', function () {
    $this->appointment->update(['format' => 'in_person']);

    callApi($this->doctor, $this->appointment, 'token')
        ->assertUnprocessable()
        ->assertJsonPath('message', 'This appointment is not a video consultation.');
    callApi($this->patient, $this->appointment, 'consent', ['consent' => true])->assertUnprocessable();

    expect($this->appointment->fresh()->status)->toBe('scheduled');
});

// --- end --------------------------------------------------------------------

it('lets the doctor end the call, closing the room and completing the visit', function () {
    fakeLiveKit();
    callApi($this->doctor, $this->appointment, 'token')->assertOk();
    $this->travel(12)->minutes();

    callApi($this->doctor, $this->appointment, 'end')
        ->assertOk()
        ->assertJsonPath('appointment.status', 'completed')
        ->assertJsonPath('appointment.durationMinutes', 12);

    Http::assertSent(fn (Request $r) => $r->url() === 'http://livekit.test/twirp/livekit.RoomService/DeleteRoom'
        && $r->data() === ['room' => "appointment-{$this->appointment->id}"]);
});

it('only lets the doctor end the call', function () {
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);

    callApi($this->patient, $this->appointment, 'end')->assertForbidden();

    expect($this->appointment->fresh()->status)->toBe('in_progress');
    Http::assertNothingSent();
});

it('cannot end a call that was never started', function () {
    callApi($this->doctor, $this->appointment, 'end')->assertStatus(409);

    Http::assertNothingSent();
});

it('keeps the visit in progress when the room cannot be closed', function () {
    fakeLiveKit(500, ['code' => 'internal', 'msg' => 'boom']);
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);

    callApi($this->doctor, $this->appointment, 'end')->assertStatus(502);

    expect($this->appointment->fresh()->status)->toBe('in_progress');
});

// --- consent ----------------------------------------------------------------

it('stores consent on the caller\'s column and pushes it to LiveKit', function () {
    fakeLiveKit();
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);

    callApi($this->patient, $this->appointment, 'consent', ['consent' => true])
        ->assertOk()
        ->assertJson(['role' => 'patient', 'consent' => true, 'live' => true]);

    $transcript = $this->appointment->fresh()->transcript;
    expect($transcript->patient_consent)->toBeTrue()
        ->and($transcript->patient_consent_at->toIso8601String())->toBe('2026-10-05T04:00:00+00:00')
        ->and($transcript->doctor_consent)->toBeNull()
        ->and($transcript->doctor_consent_at)->toBeNull();

    Http::assertSent(fn (Request $r) => str_ends_with($r->url(), '/UpdateParticipant')
        && $r->data() === [
            'room' => "appointment-{$this->appointment->id}",
            'identity' => "user-{$this->patient->id}",
            'attributes' => ['consent' => 'true'],
        ]);
});

it('flips the LiveKit attribute when consent is withdrawn', function () {
    fakeLiveKit();
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);

    callApi($this->doctor, $this->appointment, 'consent', ['consent' => true])->assertOk();
    callApi($this->doctor, $this->appointment, 'consent', ['consent' => false])
        ->assertOk()
        ->assertJson(['role' => 'doctor', 'consent' => false]);

    $transcript = $this->appointment->fresh()->transcript;
    expect($transcript->doctor_consent)->toBeFalse()
        ->and($transcript->patient_consent)->toBeNull();

    $sent = Http::recorded()->map(fn ($pair) => $pair[0]->data()['attributes']['consent'])->all();
    expect($sent)->toBe(['true', 'false']);
});

it('stores pre-call consent without contacting LiveKit', function () {
    callApi($this->doctor, $this->appointment, 'consent', ['consent' => true])
        ->assertOk()
        ->assertJson(['live' => false]);

    expect($this->appointment->fresh()->transcript->doctor_consent)->toBeTrue();
    Http::assertNothingSent();
});

it('reports a participant who is not in the room yet as not live', function () {
    fakeLiveKit(404, ['code' => 'not_found', 'msg' => 'participant not found']);
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);

    callApi($this->patient, $this->appointment, 'consent', ['consent' => true])
        ->assertOk()
        ->assertJson(['live' => false]);
});

it('keeps the stored choice but reports a failed push', function () {
    fakeLiveKit(500, ['code' => 'internal', 'msg' => 'boom']);
    $this->appointment->update(['status' => 'in_progress', 'started_at' => now()]);

    callApi($this->patient, $this->appointment, 'consent', ['consent' => false])->assertStatus(502);

    expect($this->appointment->fresh()->transcript->patient_consent)->toBeFalse();
});

it('refuses consent changes after the visit', function () {
    $this->appointment->update(['status' => 'completed']);

    callApi($this->patient, $this->appointment, 'consent', ['consent' => true])->assertStatus(409);

    expect($this->appointment->fresh()->transcript)->toBeNull();
});

it('validates the consent flag', function () {
    callApi($this->patient, $this->appointment, 'consent', [])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('consent');
});

// --- transcript policy ------------------------------------------------------

it('shows the transcript to the patient only after a transcript summary is saved', function () {
    $stranger = makePatient();

    expect($this->doctor->can('viewTranscript', $this->appointment))->toBeTrue()
        ->and($this->patient->can('viewTranscript', $this->appointment))->toBeFalse()
        ->and($stranger->can('viewTranscript', $this->appointment))->toBeFalse();

    $summary = $this->appointment->consultationSummary()->create([
        'doctor_user_id' => $this->doctor->id,
        'patient_user_id' => $this->patient->id,
        'chief_complaint' => 'Cough',
        'source' => 'doctor_note',
    ]);
    expect($this->patient->can('viewTranscript', $this->appointment))->toBeFalse();

    $summary->update(['source' => 'transcript']);
    expect($this->patient->can('viewTranscript', $this->appointment))->toBeTrue()
        ->and($stranger->can('viewTranscript', $this->appointment))->toBeFalse();
});
