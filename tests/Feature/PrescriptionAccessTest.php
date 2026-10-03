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
    $this->prescription = Prescription::factory()->create([
        'patient_user_id' => $this->patient->id,
        'doctor_user_id' => $this->doctor->id,
        'status' => 'active',
    ]);
});

function prescriptionPayload(int $patientId, ?int $appointmentId = null): array
{
    return [
        'patient_user_id' => $patientId,
        'appointment_id' => $appointmentId,
        'diagnosis_summary' => 'Viral fever',
        'medicines' => [[
            'medicine_name' => 'Paracetamol',
            'dosage' => '500mg',
            'duration_days' => 5,
        ]],
    ];
}

describe('viewing', function () {
    it('lets the patient and the prescribing doctor view it', function () {
        foreach ([$this->patient, $this->doctor] as $user) {
            $this->actingAs($user, 'sanctum')
                ->getJson("/api/v1/prescriptions/{$this->prescription->id}")
                ->assertOk();
        }
    });

    it('forbids another patient', function () {
        $this->actingAs(makePatient(), 'sanctum')
            ->getJson("/api/v1/prescriptions/{$this->prescription->id}")
            ->assertForbidden();
    });

    it('forbids another doctor', function () {
        $this->actingAs(makeDoctor(), 'sanctum')
            ->getJson("/api/v1/prescriptions/{$this->prescription->id}")
            ->assertForbidden();
    });

    it('forbids a user with no role', function () {
        $this->actingAs(\App\Models\User::factory()->create(), 'sanctum')
            ->getJson("/api/v1/prescriptions/{$this->prescription->id}")
            ->assertForbidden();
    });
});

describe('issuing', function () {
    it('lets a doctor prescribe to their own patient', function () {
        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/v1/prescriptions', prescriptionPayload($this->patient->id, $this->appointment->id))
            ->assertCreated();
    });

    it('forbids prescribing to a patient with no appointment', function () {
        $stranger = makePatient();

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/v1/prescriptions', prescriptionPayload($stranger->id))
            ->assertForbidden();
    });

    it("forbids attaching another doctor's appointment", function () {
        $otherAppointment = bookAppointment($this->patient, makeDoctor());

        $this->actingAs($this->doctor, 'sanctum')
            ->postJson('/api/v1/prescriptions', prescriptionPayload($this->patient->id, $otherAppointment->id))
            ->assertForbidden();
    });

    it('forbids an unverified doctor', function () {
        $pending = makeDoctor('pending');
        bookAppointment($this->patient, $pending);

        $this->actingAs($pending, 'sanctum')
            ->postJson('/api/v1/prescriptions', prescriptionPayload($this->patient->id))
            ->assertForbidden();
    });
});
