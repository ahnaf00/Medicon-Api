<?php

use App\Models\Prescription;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->doctor = makeDoctor();
    $this->patient = makePatient();
    $this->appointment = bookAppointment($this->patient, $this->doctor);
});

function fullPrescriptionPayload(int $patientId, int $appointmentId, array $overrides = []): array
{
    return $overrides + [
        'patient_user_id' => $patientId,
        'appointment_id' => $appointmentId,
        'diagnosis_summary' => 'Viral fever',
        'medicines' => [[
            'medicine_name' => 'Paracetamol',
            'dosage' => '500mg',
            'dosage_schedule' => ['morning' => '08:00', 'noon' => '14:00', 'night' => '20:00'],
            'instructions' => 'After Meals',
            'duration_days' => 5,
        ]],
        'tests' => [
            ['name' => 'CBC', 'instructions' => 'Fasting not required'],
            ['name' => 'Dengue NS1'],
        ],
        'follow_up_date' => now()->addDays(7)->format('Y-m-d'),
        'advice' => 'Drink plenty of fluids.',
    ];
}

it('persists tests, follow-up date and advice', function () {
    $payload = fullPrescriptionPayload($this->patient->id, $this->appointment->id);

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', $payload)
        ->assertCreated();

    $prescription = Prescription::with('tests')->sole();

    expect($prescription->appointment_id)->toBe($this->appointment->id)
        ->and($prescription->follow_up_date->format('Y-m-d'))->toBe($payload['follow_up_date'])
        ->and($prescription->advice)->toBe('Drink plenty of fluids.')
        ->and($prescription->tests->pluck('name')->all())->toBe(['CBC', 'Dengue NS1'])
        ->and($prescription->tests->first()->instructions)->toBe('Fasting not required');
});

it('still accepts a prescription without the optional fields', function () {
    $payload = fullPrescriptionPayload($this->patient->id, $this->appointment->id);
    unset($payload['tests'], $payload['follow_up_date'], $payload['advice']);

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', $payload)
        ->assertCreated();

    expect(Prescription::sole()->tests()->count())->toBe(0);
});

it('rejects a follow-up date in the past', function () {
    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', fullPrescriptionPayload(
            $this->patient->id,
            $this->appointment->id,
            ['follow_up_date' => now()->subDays(2)->format('Y-m-d')],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('follow_up_date');
});

it('rejects a test without a name', function () {
    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', fullPrescriptionPayload(
            $this->patient->id,
            $this->appointment->id,
            ['tests' => [['instructions' => 'no name']]],
        ))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('tests.0.name');
});
