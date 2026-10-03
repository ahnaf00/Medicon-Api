<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->doctor = makeDoctor();
    $this->patient = makePatient(['date_of_birth' => '1990-01-01', 'gender' => 'male']);
    bookAppointment($this->patient, $this->doctor);

    // 20:00 UTC is already the next day in Dhaka; the filename uses the Dhaka date.
    $this->travelTo(Carbon::parse('2026-09-02 20:00:00', 'UTC'));
    $this->prescription = Prescription::factory()->create([
        'patient_user_id' => $this->patient->id,
        'doctor_user_id' => $this->doctor->id,
        'advice' => '<script>alert(1)</script>',
    ]);
    PrescriptionItem::factory()->create(['prescription_id' => $this->prescription->id]);
    $this->prescription->tests()->create(['name' => 'Lipid profile', 'order' => 0]);
});

it('streams a PDF to the patient and the prescribing doctor', function () {
    foreach ([$this->patient, $this->doctor] as $user) {
        $response = $this->actingAs($user, 'sanctum')
            ->get("/api/v1/prescriptions/{$this->prescription->id}/download?format=pdf")
            ->assertOk()
            ->assertHeader('Content-Type', 'application/pdf')
            ->assertHeader(
                'Content-Disposition',
                "attachment; filename=\"Prescription_{$this->prescription->id}_2026-09-03.pdf\"",
            );

        expect(substr($response->getContent(), 0, 5))->toBe('%PDF-');
    }
});

it('defaults to PDF when no format is given', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->get("/api/v1/prescriptions/{$this->prescription->id}/download")
        ->assertOk()
        ->assertHeader('Content-Type', 'application/pdf');
});

it('rejects formats the server does not render', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$this->prescription->id}/download?format=png")
        ->assertUnprocessable()
        ->assertJsonValidationErrors('format');
});

it('forbids another patient and another doctor', function () {
    foreach ([makePatient(), makeDoctor()] as $stranger) {
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/prescriptions/{$this->prescription->id}/download")
            ->assertForbidden();
    }
});

it('requires authentication', function () {
    $this->getJson("/api/v1/prescriptions/{$this->prescription->id}/download")
        ->assertUnauthorized();
});

it('escapes user-entered text in the template', function () {
    $html = view('prescriptions.document', [
        'doc' => (new \App\Http\Resources\PrescriptionDocumentResource(
            $this->prescription->load(['items', 'tests', 'doctor.doctorProfile', 'patient.patientProfile'])
        ))->resolve(),
    ])->render();

    expect($html)
        ->toContain('&lt;script&gt;alert(1)&lt;/script&gt;')
        ->not->toContain('<script>alert(1)</script>')
        ->toContain('Lipid profile')
        ->toContain('Diagnostic Tests:');
});
