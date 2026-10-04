<?php

use App\Models\Appointment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-10-04 11:20:42');
    config([
        'services.livekit.url' => 'ws://192.168.0.10:7880',
        'services.livekit.api_key' => 'devkey',
        'services.livekit.api_secret' => 'demo-test-livekit-secret-0123456789abcd',
    ]);

    $this->doctor = makeDoctor();
    $this->doctor->update(['email' => 'doctor@medicon.com']);
    $this->patient = makePatient();
    $this->patient->update(['email' => 'patient@medicon.com']);
});

it('creates a video appointment for the test accounts that starts now', function () {
    $this->artisan('demo:video-call')->assertSuccessful();

    $appointment = Appointment::latest('id')->first();
    expect($appointment->doctor_user_id)->toBe($this->doctor->id)
        ->and($appointment->patient_user_id)->toBe($this->patient->id)
        ->and($appointment->format)->toBe('video')
        ->and($appointment->status)->toBe('scheduled')
        ->and($appointment->appointment_datetime->toDateTimeString())->toBe('2026-10-04 11:20:00');
});

it('creates an appointment the doctor can start and the patient can then join', function () {
    $this->artisan('demo:video-call')->assertSuccessful();
    $appointment = Appointment::latest('id')->first();

    $this->actingAs($this->patient, 'sanctum')
        ->postJson("/api/v1/appointments/{$appointment->id}/call/token")->assertStatus(409);
    $this->actingAs($this->doctor, 'sanctum')
        ->postJson("/api/v1/appointments/{$appointment->id}/call/token")->assertOk();
    $this->actingAs($this->patient, 'sanctum')
        ->postJson("/api/v1/appointments/{$appointment->id}/call/token")->assertOk();
});

it('accepts other accounts by id or phone', function () {
    $otherPatient = makePatient();
    $otherPatient->update(['phone' => '01800000099']);

    $this->artisan('demo:video-call', ['--patient' => '01800000099', '--doctor' => (string) $this->doctor->id])
        ->assertSuccessful();

    expect(Appointment::latest('id')->first()->patient_user_id)->toBe($otherPatient->id);
});

it('refuses an unverified doctor', function () {
    $pending = makeDoctor('pending');

    $this->artisan('demo:video-call', ['--doctor' => (string) $pending->id])
        ->expectsOutputToContain('doctor:verify')
        ->assertFailed();

    expect(Appointment::count())->toBe(0);
});

it('refuses unknown accounts', function () {
    $this->artisan('demo:video-call', ['--patient' => 'nobody@example.com'])->assertFailed();

    expect(Appointment::count())->toBe(0);
});

it('never runs in production', function () {
    $this->app->detectEnvironment(fn () => 'production');

    $this->artisan('demo:video-call')->assertFailed();

    expect(Appointment::count())->toBe(0);
});
