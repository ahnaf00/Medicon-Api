<?php

use App\Models\Prescription;
use App\Models\Vital;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Task H3 part 2 (audit DB-003): prescriptions and vitals come in pages.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
});

function rxFor($patient, $doctor, string $status, string $at): Prescription
{
    return Prescription::factory()->create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'status' => $status,
        'created_at' => $at,
    ]);
}

describe('prescriptions', function () {
    it('sends 20 per page, newest first', function () {
        foreach (range(1, 23) as $day) {
            rxFor($this->patient, $this->doctor, 'completed', sprintf('2026-09-%02d 04:00:00', $day));
        }

        $first = $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/prescriptions')->assertOk();
        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('meta.total'))->toBe(23)
            ->and($first->json('meta.last_page'))->toBe(2);

        $all = collect($first->json('data'))
            ->merge($this->getJson('/api/v1/prescriptions?page=2')->json('data'))
            ->pluck('id');
        expect($all)->toHaveCount(23)
            ->and($all->unique())->toHaveCount(23)
            ->and($all->first())->toBe(Prescription::latest()->first()->id);
    });

    it('filters by status, for the interaction checker and the medicine widget', function () {
        $active = rxFor($this->patient, $this->doctor, 'active', '2026-10-01 04:00:00');
        rxFor($this->patient, $this->doctor, 'completed', '2026-10-02 04:00:00');
        rxFor($this->patient, $this->doctor, 'cancelled', '2026-10-03 04:00:00');

        $ids = collect($this->actingAs($this->patient, 'sanctum')
            ->getJson('/api/v1/prescriptions?status=active')->assertOk()->json('data'))->pluck('id')->all();

        expect($ids)->toBe([$active->id]);
        $this->getJson('/api/v1/prescriptions?status=lost')->assertStatus(422);
    });

    it('filters a doctor\'s prescriptions to one patient, inside their own only', function () {
        $other = makePatient();
        $mine = rxFor($this->patient, $this->doctor, 'active', '2026-10-01 04:00:00');
        rxFor($other, $this->doctor, 'active', '2026-10-02 04:00:00');
        rxFor($this->patient, makeDoctor(), 'active', '2026-10-03 04:00:00');

        $ids = collect($this->actingAs($this->doctor, 'sanctum')
            ->getJson("/api/v1/prescriptions?patient_user_id={$this->patient->id}")->json('data'))->pluck('id')->all();
        expect($ids)->toBe([$mine->id]);

        // A patient can't list someone else's prescriptions with the filter.
        expect($this->actingAs($other, 'sanctum')
            ->getJson("/api/v1/prescriptions?patient_user_id={$this->patient->id}")->json('data'))->toBe([]);
    });
});

describe('vitals', function () {
    it('sends 20 readings per page, newest first, only the caller\'s', function () {
        foreach (range(1, 22) as $day) {
            Vital::factory()->create(['user_id' => $this->patient->id, 'logged_at' => sprintf('2026-09-%02d 04:00:00', $day)]);
        }
        Vital::factory()->create(['user_id' => makePatient()->id, 'logged_at' => '2026-09-30 04:00:00']);

        $first = $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/vitals')->assertOk();
        expect($first->json('data'))->toHaveCount(20)
            ->and($first->json('meta.total'))->toBe(22)
            ->and(Vital::find($first->json('data.0.id'))->logged_at->toDateString())->toBe('2026-09-22');

        expect($this->getJson('/api/v1/vitals?page=2')->json('data'))->toHaveCount(2);
        $this->getJson('/api/v1/vitals?per_page=51')->assertStatus(422);
    });
});
