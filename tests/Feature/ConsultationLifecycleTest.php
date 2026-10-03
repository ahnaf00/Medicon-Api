<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-10-05 04:00:00');
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
});

function setStatus($user, $appointment, string $status)
{
    return test()->actingAs($user, 'sanctum')
        ->patchJson("/api/v1/appointments/{$appointment->id}/status", ['status' => $status]);
}

it('starts and completes a visit, writing its duration', function () {
    setStatus($this->doctor, $this->appointment, 'in_progress')
        ->assertOk()
        ->assertJsonPath('appointment.status', 'in_progress')
        ->assertJsonPath('appointment.startedAt', '2026-10-05T04:00:00+00:00');

    $this->travel(17)->minutes();

    setStatus($this->doctor, $this->appointment, 'completed')
        ->assertOk()
        ->assertJsonPath('appointment.status', 'completed')
        ->assertJsonPath('appointment.endedAt', '2026-10-05T04:17:00+00:00')
        ->assertJsonPath('appointment.durationMinutes', 17);

    expect($this->appointment->fresh()->duration_minutes)->toBe(17);
});

it('marks a scheduled visit as a no-show', function () {
    setStatus($this->doctor, $this->appointment, 'no_show')
        ->assertOk()
        ->assertJsonPath('appointment.status', 'no_show');
});

it('rejects transitions the lifecycle does not allow', function (string $from, string $to) {
    $this->appointment->update(['status' => $from]);

    setStatus($this->doctor, $this->appointment, $to)->assertStatus(409);
    expect($this->appointment->fresh()->status)->toBe($from);
})->with([
    'complete without starting' => ['scheduled', 'completed'],
    'restart a completed visit' => ['completed', 'in_progress'],
    'no-show after starting' => ['in_progress', 'no_show'],
    'revive a cancelled visit' => ['cancelled', 'in_progress'],
]);

it('rejects unknown statuses', function () {
    setStatus($this->doctor, $this->appointment, 'cancelled')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('status');
});

it('only lets the appointment\'s own doctor change its status', function () {
    setStatus(makeDoctor(), $this->appointment, 'in_progress')->assertForbidden();
    setStatus($this->patient, $this->appointment, 'in_progress')->assertForbidden();

    expect($this->appointment->fresh()->status)->toBe('scheduled');
});

it('keeps an in-progress visit\'s slot booked', function () {
    $this->appointment->update(['appointment_datetime' => '2026-10-05 05:00:00', 'status' => 'in_progress']);

    $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?date=2026-10-05")
        ->assertJsonPath('slots.4.time', '11:00')
        ->assertJsonPath('slots.4.available', false);
});
