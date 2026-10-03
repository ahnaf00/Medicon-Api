<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

describe('doctor verification', function () {
    it('blocks an unverified (self-registered) doctor from doctor routes', function () {
        $doctor = makeDoctor('pending');

        $this->actingAs($doctor, 'sanctum')
            ->getJson('/api/v1/patients')
            ->assertForbidden();
    });

    it('blocks a self-registered doctor straight after registration', function () {
        $token = $this->postJson('/api/v1/auth/register', [
            'name' => 'Not A Doctor',
            'phone' => '+8801700000001',
            'role' => 'doctor',
            'specialty' => 'Cardiology',
            'qualification' => 'MBBS',
        ])->assertCreated()->json('access_token');

        $this->withToken($token)
            ->getJson('/api/v1/doctor/dashboard')
            ->assertForbidden();
    });

    it('allows a verified doctor through', function () {
        $this->actingAs(makeDoctor(), 'sanctum')
            ->getJson('/api/v1/patients')
            ->assertOk();
    });
});

describe('patient profile access', function () {
    it('lets a doctor view a patient they have an appointment with', function () {
        $doctor = makeDoctor();
        $patient = makePatient();
        bookAppointment($patient, $doctor);

        $this->actingAs($doctor, 'sanctum')
            ->getJson("/api/v1/patients/{$patient->id}")
            ->assertOk()
            ->assertJsonPath('data.id', $patient->id);
    });

    it('forbids a doctor from viewing a patient with no appointment', function () {
        $patient = makePatient();

        $this->actingAs(makeDoctor(), 'sanctum')
            ->getJson("/api/v1/patients/{$patient->id}")
            ->assertForbidden();
    });

    it('forbids a patient from the doctor-only patient endpoint', function () {
        $other = makePatient();

        $this->actingAs(makePatient(), 'sanctum')
            ->getJson("/api/v1/patients/{$other->id}")
            ->assertForbidden();
    });
});

describe('appointment cancellation', function () {
    it('lets a participant cancel', function () {
        $patient = makePatient();
        $appointment = bookAppointment($patient, makeDoctor());

        $this->actingAs($patient, 'sanctum')
            ->patchJson("/api/v1/appointments/{$appointment->id}/cancel")
            ->assertOk();

        expect($appointment->fresh()->status)->toBe('cancelled');
    });

    it('forbids a stranger from cancelling', function () {
        $appointment = bookAppointment(makePatient(), makeDoctor());

        $this->actingAs(makePatient(), 'sanctum')
            ->patchJson("/api/v1/appointments/{$appointment->id}/cancel")
            ->assertForbidden();

        expect($appointment->fresh()->status)->toBe('scheduled');
    });
});
