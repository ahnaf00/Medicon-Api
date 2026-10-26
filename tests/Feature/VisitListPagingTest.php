<?php

use App\Models\Appointment;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Task H3 part 2 (audit DB-003): GET /appointments comes in pages, with filters;
// GET /appointments/{id} returns one visit to either person in it.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->travelTo('2026-10-10 06:00:00');
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
});

function h3Visit(User $patient, User $doctor, string $at, string $status = 'scheduled'): Appointment
{
    return Appointment::factory()->create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'appointment_datetime' => $at,
        'status' => $status,
        'format' => 'video',
    ]);
}

function h3VisitIds($response): array
{
    return collect($response->json('data'))->pluck('id')->all();
}

describe('list', function () {
    it('sends 20 visits per page, newest first', function () {
        foreach (range(1, 25) as $day) {
            h3Visit($this->patient, $this->doctor, sprintf('2026-09-%02d 04:00:00', $day), 'completed');
        }

        $first = $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/appointments')->assertOk();
        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('data.0.datetime'))->toStartWith('2026-09-25')
            ->and($first->json('meta.total'))->toBe(25)
            ->and($first->json('meta.last_page'))->toBe(2);

        $second = $this->getJson('/api/v1/appointments?page=2')->assertOk();
        expect($second->json('data'))->toHaveCount(5)
            ->and($second->json('data.4.datetime'))->toStartWith('2026-09-01');
    });

    it('splits upcoming (soonest first) from past (newest first)', function () {
        $later = h3Visit($this->patient, $this->doctor, '2026-10-12 04:00:00');
        $soon = h3Visit($this->patient, $this->doctor, '2026-10-11 04:00:00');
        $running = h3Visit($this->patient, $this->doctor, '2026-10-10 05:45:00', 'in_progress');
        $done = h3Visit($this->patient, $this->doctor, '2026-10-01 04:00:00', 'completed');
        $cancelled = h3Visit($this->patient, $this->doctor, '2026-10-05 04:00:00', 'cancelled');

        $this->actingAs($this->patient, 'sanctum');
        expect(h3VisitIds($this->getJson('/api/v1/appointments?when=upcoming')))
            ->toBe([$running->id, $soon->id, $later->id])
            ->and(h3VisitIds($this->getJson('/api/v1/appointments?when=past')))
            ->toBe([$cancelled->id, $done->id])
            ->and(h3VisitIds($this->getJson('/api/v1/appointments?status=completed')))
            ->toBe([$done->id]);
    });

    it('keeps only visits that start inside a from-to window', function () {
        h3Visit($this->patient, $this->doctor, '2026-10-09 17:59:00');
        $inside = h3Visit($this->patient, $this->doctor, '2026-10-10 03:00:00');
        h3Visit($this->patient, $this->doctor, '2026-10-10 18:00:00');

        // One Dhaka day: 10 Oct 00:00 to 11 Oct 00:00 (+06:00).
        $ids = h3VisitIds($this->actingAs($this->doctor, 'sanctum')->getJson(
            '/api/v1/appointments?from='.urlencode('2026-10-10T00:00:00+06:00').'&to='.urlencode('2026-10-11T00:00:00+06:00')
        )->assertOk());

        expect($ids)->toBe([$inside->id]);
        $this->getJson('/api/v1/appointments?from='.urlencode('2026-10-10T00:00:00+06:00'))->assertStatus(422);
    });

    it('filters a doctor\'s visits to one patient, and never shows anyone else\'s', function () {
        $other = makePatient();
        $mine = h3Visit($this->patient, $this->doctor, '2026-10-11 04:00:00');
        $theirs = h3Visit($other, $this->doctor, '2026-10-11 05:00:00');
        h3Visit($other, makeDoctor(), '2026-10-11 06:00:00');

        expect(h3VisitIds($this->actingAs($this->doctor, 'sanctum')
            ->getJson("/api/v1/appointments?patient_user_id={$this->patient->id}")))->toBe([$mine->id]);

        // A patient asking for another patient's visits gets none of them.
        expect(h3VisitIds($this->actingAs($this->patient, 'sanctum')
            ->getJson("/api/v1/appointments?patient_user_id={$other->id}")))->toBe([])
            ->and(h3VisitIds($this->getJson('/api/v1/appointments')))->toBe([$mine->id])
            ->and(h3VisitIds($this->actingAs($this->doctor, 'sanctum')->getJson('/api/v1/appointments')))
            ->toEqualCanonicalizing([$mine->id, $theirs->id]);
    });

    it('refuses a page size over 50', function () {
        $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/appointments?per_page=51')->assertStatus(422);
    });
});

describe('one visit', function () {
    it('shows a visit to the patient and the doctor, with the right summary flag', function () {
        $visit = h3Visit($this->patient, $this->doctor, '2026-10-09 04:00:00', 'completed');
        // A draft summary (not shared with the patient).
        $this->actingAs($this->doctor, 'sanctum')->putJson("/api/v1/consultations/{$visit->id}/summary", [
            'chief_complaint' => 'Headache',
            'findings' => 'Normal examination.',
            'advice' => 'Rest and fluids.',
        ])->assertCreated();

        $this->actingAs($this->doctor, 'sanctum')->getJson("/api/v1/appointments/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $visit->id)
            ->assertJsonPath('data.patient.id', $this->patient->id)
            ->assertJsonPath('data.hasSummary', true);

        // The draft isn't shared yet, so the patient doesn't see a summary.
        $this->actingAs($this->patient, 'sanctum')->getJson("/api/v1/appointments/{$visit->id}")
            ->assertOk()
            ->assertJsonPath('data.doctor.id', $this->doctor->id)
            ->assertJsonPath('data.hasSummary', false);
    });

    it('refuses anyone who is not in the visit', function () {
        $visit = h3Visit($this->patient, $this->doctor, '2026-10-11 04:00:00');

        $this->actingAs(makePatient(), 'sanctum')->getJson("/api/v1/appointments/{$visit->id}")->assertForbidden();
        $this->actingAs(makeDoctor(), 'sanctum')->getJson("/api/v1/appointments/{$visit->id}")->assertForbidden();
        $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/appointments/999999')->assertNotFound();
    });
});
