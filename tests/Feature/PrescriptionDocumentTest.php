<?php

use App\Http\Resources\PrescriptionDocumentResource;
use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->doctor = makeDoctor();
    $this->doctor->doctorProfile->update([
        'qualification' => 'MBBS, FCPS',
        'bmdc_registration_no' => 'A-28451',
        'hospital_name' => 'Dhaka Medical College Hospital',
    ]);
    $this->patient = makePatient([
        'date_of_birth' => '1991-05-21',
        'gender' => 'female',
        'weight_kg' => 58.5,
    ]);
    bookAppointment($this->patient, $this->doctor);

    $this->travelTo(Carbon::parse('2026-09-02 10:00:00', 'UTC'));
    $this->prescription = Prescription::factory()->create([
        'patient_user_id' => $this->patient->id,
        'doctor_user_id' => $this->doctor->id,
        'follow_up_date' => '2026-09-16',
        'advice' => 'Rest and hydrate.',
    ]);
    PrescriptionItem::factory()->create([
        'prescription_id' => $this->prescription->id,
        'medicine_name' => 'Napa',
        'dosage' => '500mg',
        'dosage_schedule' => ['morning' => '08:00', 'night' => '20:00'],
        'duration_days' => 7,
    ]);
    $this->prescription->tests()->create(['name' => 'CBC', 'order' => 0]);
});

it('returns everything the letterhead needs', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$this->prescription->id}/document")
        ->assertOk()
        ->assertJsonPath('data.issuedDate', 'Sep 2, 2026')
        ->assertJsonPath('data.doctor.bmdcRegistrationNo', 'A-28451')
        ->assertJsonPath('data.doctor.hospitalName', 'Dhaka Medical College Hospital')
        ->assertJsonPath('data.doctor.qualification', 'MBBS, FCPS')
        ->assertJsonPath('data.patient.gender', 'female')
        ->assertJsonPath('data.patient.age', '35y 3m 12d')
        ->assertJsonPath('data.patient.weightKg', 58.5)
        ->assertJsonPath('data.tests.0.name', 'CBC')
        ->assertJsonPath('data.medicines.0.name', 'Napa')
        ->assertJsonPath('data.medicines.0.pattern', '1+0+1')
        ->assertJsonPath('data.followUpDate', '2026-09-16')
        ->assertJsonPath('data.advice', 'Rest and hydrate.');
});

it('uses the Dhaka calendar date when UTC is still on the previous day', function () {
    $this->prescription->forceFill(['created_at' => Carbon::parse('2026-09-02 20:00:00', 'UTC')])->save();

    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$this->prescription->id}/document")
        ->assertJsonPath('data.issuedDate', 'Sep 3, 2026')
        ->assertJsonPath('data.patient.age', '35y 3m 13d');
});

it('returns null rather than guessing missing profile fields', function () {
    $this->patient->patientProfile->update(['date_of_birth' => null, 'weight_kg' => null]);

    $this->actingAs($this->doctor, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$this->prescription->id}/document")
        ->assertOk()
        ->assertJsonPath('data.patient.age', null)
        ->assertJsonPath('data.patient.weightKg', null);
});

it('forbids another patient and another doctor', function () {
    foreach ([makePatient(), makeDoctor()] as $stranger) {
        $this->actingAs($stranger, 'sanctum')
            ->getJson("/api/v1/prescriptions/{$this->prescription->id}/document")
            ->assertForbidden();
    }
});

it('exposes the new fields on the regular prescription and profile resources', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$this->prescription->id}")
        ->assertOk()
        ->assertJsonPath('data.followUpDate', '2026-09-16')
        ->assertJsonPath('data.advice', 'Rest and hydrate.')
        ->assertJsonPath('data.tests.0.name', 'CBC')
        ->assertJsonPath('data.doctor.doctorProfile.bmdcRegistrationNo', 'A-28451')
        ->assertJsonPath('data.patient.patientProfile.weightKg', 58.5);
});

it('formats the dose pattern from morning, noon and night', function (?array $schedule, ?string $expected) {
    expect(PrescriptionDocumentResource::dosePattern($schedule))->toBe($expected);
})->with([
    'all three' => [['morning' => '08:00', 'noon' => '14:00', 'night' => '20:00'], '1+1+1'],
    'noon only' => [['noon' => '14:00'], '0+1+0'],
    'no schedule' => [null, null],
]);

it('computes age across a short month without overflowing', function () {
    // Jan 31 → Mar 1 is 29 days, not a full month (there is no Feb 31).
    expect(PrescriptionDocumentResource::formatAge(Carbon::parse('2000-01-31'), Carbon::parse('2026-03-01')))
        ->toBe('26y 0m 29d');
});
