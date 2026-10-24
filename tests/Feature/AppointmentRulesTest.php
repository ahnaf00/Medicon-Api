<?php

use App\Models\Appointment;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit F10 (API-011): start window, expiry, cancel cut-off, reschedule, no overlaps.

// Monday 2026-10-05 09:00 Dhaka, a free slot in the default 09:00–17:00 hours.
const RULES_SLOT = '2026-10-05T03:00:00+00:00';

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    // Sunday 2026-10-04 08:00 Dhaka.
    $this->travelTo('2026-10-04 02:00:00');
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
});

function rulesBook($patient, $doctor, string $at)
{
    return test()->actingAs($patient, 'sanctum')->postJson('/api/v1/appointments', [
        'doctor_user_id' => $doctor->id,
        'appointment_datetime' => $at,
        'format' => 'video',
    ]);
}

function rulesVisit(string $at = RULES_SLOT, string $status = 'scheduled'): Appointment
{
    return Appointment::factory()->create([
        'patient_user_id' => test()->patient->id,
        'doctor_user_id' => test()->doctor->id,
        'appointment_datetime' => $at,
        'status' => $status,
        'format' => 'video',
    ]);
}

describe('start window', function () {
    it('lets the doctor start from 15 minutes before, not earlier', function () {
        $visit = rulesVisit();

        $this->travelTo('2026-10-05 02:44:00');
        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/status", ['status' => 'in_progress'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This visit is at 9:00 AM. It can be started from 8:45 AM.');

        $this->travelTo('2026-10-05 02:45:00');
        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/status", ['status' => 'in_progress'])
            ->assertOk();
    });

    it('does not start a visit more than 2 hours late', function () {
        $visit = rulesVisit();
        $this->travelTo('2026-10-05 05:01:00');

        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/status", ['status' => 'in_progress'])
            ->assertStatus(409)
            ->assertJsonPath('message', 'This visit can no longer be started: it was due at 9:00 AM.');
    });

    it('applies the window to the doctor joining the video call', function () {
        $visit = rulesVisit();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson("/api/v1/appointments/{$visit->id}/call/token")
            ->assertStatus(409);

        expect($visit->fresh()->status)->toBe('scheduled')
            ->and($visit->transcript()->exists())->toBeFalse();
    });

    it('allows a no-show only after the start time', function () {
        $visit = rulesVisit();

        $this->travelTo('2026-10-05 02:59:00');
        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/status", ['status' => 'no_show'])
            ->assertStatus(409);

        $this->travelTo('2026-10-05 03:00:00');
        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/status", ['status' => 'no_show'])
            ->assertOk();
    });
});

describe('expiry', function () {
    it('expires visits nobody started and closes visits left in progress', function () {
        $late = rulesVisit('2026-10-05 03:00:00');
        $stillStartable = rulesVisit('2026-10-05 03:30:00');
        $longCall = rulesVisit('2026-10-04 23:00:00', 'in_progress');
        $longCall->update(['started_at' => '2026-10-04 23:00:00']);
        $liveCall = rulesVisit('2026-10-05 04:00:00', 'in_progress');
        $liveCall->update(['started_at' => '2026-10-05 04:00:00']);

        // 2 h 1 min after the first visit's time.
        $this->travelTo('2026-10-05 05:01:00');
        $this->artisan('appointments:expire')->assertSuccessful();

        expect($late->fresh()->status)->toBe('expired')
            ->and($stillStartable->fresh()->status)->toBe('scheduled')
            ->and($longCall->fresh()->status)->toBe('completed')
            ->and($longCall->fresh()->ended_at)->not->toBeNull()
            ->and($liveCall->fresh()->status)->toBe('in_progress');
    });

    it('lists an expired visit as expired to the patient', function () {
        $visit = rulesVisit();
        $this->travelTo('2026-10-05 06:00:00');
        $this->artisan('appointments:expire');

        $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/appointments')
            ->assertOk()
            ->assertJsonPath('data.0.status', 'expired');
        expect($visit->fresh()->status)->toBe('expired');
    });
});

describe('cancel cut-off', function () {
    it('lets the patient cancel until 1 hour before, not after', function () {
        $visit = rulesVisit();

        $this->travelTo('2026-10-05 02:01:00');
        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/cancel")
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'A visit can be cancelled or moved only until 60 minutes before it starts.');

        $this->travelTo('2026-10-05 01:59:00');
        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/cancel")
            ->assertOk();
    });

    it('lets the doctor cancel inside the cut-off', function () {
        $visit = rulesVisit();
        $this->travelTo('2026-10-05 02:50:00');

        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/cancel")
            ->assertOk();
        expect($visit->fresh()->status)->toBe('cancelled');
    });
});

describe('reschedule', function () {
    it('moves the visit to another free slot, keeping the same appointment', function () {
        $visit = rulesVisit();

        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/reschedule", [
                'appointment_datetime' => '2026-10-05T04:00:00+00:00',
            ])
            ->assertOk()
            ->assertJsonPath('appointment.id', $visit->id)
            ->assertJsonPath('appointment.status', 'scheduled');

        expect($visit->fresh()->appointment_datetime->toIso8601String())->toBe('2026-10-05T04:00:00+00:00');

        // The old slot is free again.
        $this->getJson("/api/v1/doctors/{$this->doctor->id}/slots?date=2026-10-05")
            ->assertJsonPath('slots.0.available', true)
            ->assertJsonPath('slots.2.available', false);
    });

    it('refuses a taken slot, a time outside hours, the same time and a past time', function (string $at) {
        $other = makePatient();
        Appointment::factory()->create([
            'patient_user_id' => $other->id, 'doctor_user_id' => $this->doctor->id,
            'appointment_datetime' => '2026-10-05 04:00:00', 'status' => 'scheduled',
        ]);
        $visit = rulesVisit();

        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/reschedule", ['appointment_datetime' => $at])
            ->assertStatus(422);
        expect($visit->fresh()->appointment_datetime->toIso8601String())->toBe(RULES_SLOT);
    })->with([
        'taken' => '2026-10-05T04:00:00+00:00',
        'outside hours' => '2026-10-05T14:00:00+00:00',
        'same time' => RULES_SLOT,
        'past' => '2026-10-03T04:00:00+00:00',
    ]);

    it('refuses inside the cut-off and for a visit that is not scheduled', function () {
        $visit = rulesVisit();
        $this->travelTo('2026-10-05 02:30:00');
        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/reschedule", ['appointment_datetime' => '2026-10-05T05:00:00+00:00'])
            ->assertStatus(422);

        $cancelled = rulesVisit('2026-10-06 03:00:00', 'cancelled');
        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$cancelled->id}/reschedule", ['appointment_datetime' => '2026-10-06T05:00:00+00:00'])
            ->assertStatus(422);
    });

    it('lets only the patient who booked it move it', function () {
        $visit = rulesVisit();
        $body = ['appointment_datetime' => '2026-10-05T05:00:00+00:00'];

        $this->actingAs(makePatient(), 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/reschedule", $body)
            ->assertForbidden();
        $this->actingAs($this->doctor, 'sanctum')
            ->patchJson("/api/v1/appointments/{$visit->id}/reschedule", $body)
            ->assertForbidden();
        expect($visit->fresh()->appointment_datetime->toIso8601String())->toBe(RULES_SLOT);
    });
});

describe('overlapping visits', function () {
    it('stops a patient booking two doctors at the same time', function () {
        $otherDoctor = makeDoctor();
        rulesBook($this->patient, $this->doctor, RULES_SLOT)->assertCreated();

        rulesBook($this->patient, $otherDoctor, RULES_SLOT)
            ->assertStatus(422)
            ->assertJsonPath('errors.appointment_datetime.0', "You already have a visit with {$this->doctor->name} at 9:00 AM. Choose another time.");

        // The next slot does not overlap a 30-minute visit.
        rulesBook($this->patient, $otherDoctor, '2026-10-05T03:30:00+00:00')->assertCreated();
    });

    it('ignores cancelled and expired visits', function () {
        rulesVisit(RULES_SLOT, 'cancelled');
        rulesBook($this->patient, makeDoctor(), RULES_SLOT)->assertCreated();
    });

    it('stops a move onto another visit of the same patient', function () {
        $otherDoctor = makeDoctor();
        $first = rulesVisit();
        rulesBook($this->patient, $otherDoctor, '2026-10-05T04:00:00+00:00')->assertCreated();

        $this->actingAs($this->patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$first->id}/reschedule", ['appointment_datetime' => '2026-10-05T04:00:00+00:00'])
            ->assertStatus(422)
            ->assertJsonPath('errors.appointment_datetime.0', "You already have a visit with {$otherDoctor->name} at 10:00 AM. Choose another time.");
    });
});
