<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it("includes a doctor's profile, so qualifications reach the dashboard", function () {
    $doctor = makeDoctor();
    $doctor->doctorProfile->update(['qualification' => 'MBBS, FCPS']);

    $this->actingAs($doctor, 'sanctum')
        ->getJson('/api/v1/user/me')
        ->assertOk()
        ->assertJsonPath('data.doctorProfile.qualification', 'MBBS, FCPS');
});

it("includes a patient's profile", function () {
    $patient = makePatient(['blood_group' => 'O+']);

    $this->actingAs($patient, 'sanctum')
        ->getJson('/api/v1/user/me')
        ->assertOk()
        ->assertJsonPath('data.patientProfile.bloodGroup', 'O+');
});
