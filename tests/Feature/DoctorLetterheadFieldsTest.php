<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit D2: the BMDC number and hospital printed on prescriptions can be entered,
// and the licence is no longer saved as the qualification.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

function registerDoctor(array $overrides = [])
{
    return test()->postJson('/api/v1/auth/register', array_merge([
        'name' => 'Dr. New',
        'phone' => '+8801700000009',
        'role' => 'doctor',
        'registration_token' => verifyNewPhone('+8801700000009'),
        'specialty' => 'Cardiology',
        'qualification' => 'MBBS, FCPS',
        'bmdc_registration_no' => 'A-12345',
        'hospital_name' => 'Dhaka Medical College Hospital',
    ], $overrides));
}

it('saves the BMDC number, qualification and hospital separately at sign-up', function () {
    registerDoctor()->assertCreated();

    expect(User::where('phone', '+8801700000009')->sole()->doctorProfile)
        ->bmdc_registration_no->toBe('A-12345')
        ->qualification->toBe('MBBS, FCPS')
        ->hospital_name->toBe('Dhaka Medical College Hospital');
});

it('requires a BMDC number with digits for a doctor', function (array $overrides) {
    registerDoctor($overrides)->assertUnprocessable()->assertJsonValidationErrors('bmdc_registration_no');
})->with([
    'missing' => [['bmdc_registration_no' => null]],
    'no digits' => [['bmdc_registration_no' => 'BMDC']],
]);

it('lets a pending doctor fill in the letterhead fields', function () {
    $doctor = makeDoctor('pending');

    $this->actingAs($doctor, 'sanctum')
        ->putJson('/api/v1/user/me', ['bmdc_registration_no' => 'A-54321', 'hospital_name' => 'Square Hospital'])
        ->assertOk();

    expect($doctor->doctorProfile->fresh())
        ->bmdc_registration_no->toBe('A-54321')
        ->hospital_name->toBe('Square Hospital');
});

it('lets a verified doctor change the hospital but not the verified BMDC number', function () {
    $doctor = makeDoctor();
    $doctor->doctorProfile->update(['bmdc_registration_no' => 'A-11111']);

    $this->actingAs($doctor, 'sanctum')
        ->putJson('/api/v1/user/me', ['hospital_name' => 'New Chamber'])
        ->assertOk();
    $this->actingAs($doctor, 'sanctum')
        ->putJson('/api/v1/user/me', ['bmdc_registration_no' => 'A-22222'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('bmdc_registration_no');

    expect($doctor->doctorProfile->fresh())
        ->hospital_name->toBe('New Chamber')
        ->bmdc_registration_no->toBe('A-11111');
});

it('keeps a doctor\'s department on the list when editing the profile', function () {
    $doctor = makeDoctor();

    $this->actingAs($doctor, 'sanctum')
        ->putJson('/api/v1/user/me', ['specialty' => 'Cardiologist'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('specialty');
});

it('prints the BMDC number and hospital on the prescription document', function () {
    $doctor = makeDoctor();
    $doctor->doctorProfile->update(['bmdc_registration_no' => 'A-12345', 'hospital_name' => 'Square Hospital']);
    $patient = makePatient();
    $rx = \App\Models\Prescription::factory()->create(['doctor_user_id' => $doctor->id, 'patient_user_id' => $patient->id]);

    $this->actingAs($patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$rx->id}/document")
        ->assertOk()
        ->assertJsonPath('data.doctor.bmdcRegistrationNo', 'A-12345')
        ->assertJsonPath('data.doctor.hospitalName', 'Square Hospital');
});
