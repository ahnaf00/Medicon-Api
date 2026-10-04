<?php

use App\Models\Appointment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Sunday 2026-10-04 08:00 Dhaka; Monday 09:00 Dhaka (03:00 UTC) is a default slot.
    $this->travelTo('2026-10-04 02:00:00');
    $this->patient = makePatient();
    $this->verified = makeDoctor();
    $this->pending = makeDoctor('pending');
});

function bookWith($doctor)
{
    return test()->actingAs(test()->patient, 'sanctum')->postJson('/api/v1/appointments', [
        'doctor_user_id' => $doctor->id,
        'appointment_datetime' => '2026-10-05T03:00:00+00:00',
        'format' => 'video',
    ]);
}

// --- patients only see and book verified doctors ----------------------------

it('lists only verified doctors', function () {
    $ids = collect($this->getJson('/api/v1/doctors')->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toContain($this->verified->id)->not->toContain($this->pending->id);
});

it('hides a pending doctor\'s profile and slots', function () {
    $this->getJson("/api/v1/doctors/{$this->pending->id}")->assertNotFound();
    $this->getJson("/api/v1/doctors/{$this->pending->id}/slots?date=2026-10-05")->assertNotFound();

    $this->getJson("/api/v1/doctors/{$this->verified->id}")->assertOk();
    $this->getJson("/api/v1/doctors/{$this->verified->id}/slots?date=2026-10-05")->assertOk();
});

it('refuses to book a pending doctor', function () {
    bookWith($this->pending)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('appointment_datetime');

    expect(Appointment::where('doctor_user_id', $this->pending->id)->exists())->toBeFalse();
});

it('still books a verified doctor at the same slot', function () {
    bookWith($this->verified)->assertCreated();
});

// --- doctor:verify ----------------------------------------------------------

it('verifies a pending doctor by id, unlocking doctor features', function () {
    $this->artisan('doctor:verify', ['doctor' => (string) $this->pending->id])
        ->expectsOutputToContain("Verified Dr. #{$this->pending->id}")
        ->assertSuccessful();

    expect($this->pending->fresh()->isVerifiedDoctor())->toBeTrue();
    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/doctor/dashboard')->assertOk();
});

it('verifies a doctor by exact phone number', function () {
    $this->pending->update(['phone' => '01711000009']);

    $this->artisan('doctor:verify', ['doctor' => '01711000009'])->assertSuccessful();

    expect($this->pending->doctorProfile->fresh()->verification_status)->toBe('verified');
});

it('refuses to verify a patient or an unknown user', function () {
    $this->artisan('doctor:verify', ['doctor' => (string) $this->patient->id])->assertFailed();
    $this->artisan('doctor:verify', ['doctor' => '999999'])->assertFailed();

    expect($this->patient->fresh()->doctorProfile)->toBeNull();
});

it('keeps pending doctors out of doctor-only endpoints until verified', function () {
    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/doctor/dashboard')->assertForbidden();
});
