<?php

use App\Models\AiChatSession;
use App\Models\DoctorAvailability;
use App\Models\PhoneOtp;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit DB-001: one row per key, enforced by the database.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('allows one availability row per doctor and weekday', function () {
    $doctor = makeDoctor();
    $row = ['doctor_user_id' => $doctor->id, 'day_of_week' => 1, 'start_time' => '09:00:00', 'end_time' => '12:00:00'];
    DoctorAvailability::query()->delete();
    DoctorAvailability::create($row);

    expect(fn () => DoctorAvailability::create($row))->toThrow(UniqueConstraintViolationException::class);
});

it('allows one code row per phone', function () {
    PhoneOtp::create(['phone' => '+8801700000000', 'otp' => 'x', 'expires_at' => now()->addMinutes(5)]);

    expect(fn () => PhoneOtp::create(['phone' => '+8801700000000', 'otp' => 'y', 'expires_at' => now()->addMinutes(5)]))
        ->toThrow(UniqueConstraintViolationException::class);
});

it('allows one consultation chat per patient and appointment, and any number of general chats', function () {
    $patient = makePatient();
    $appointment = bookAppointment($patient, makeDoctor());

    AiChatSession::create(['user_id' => $patient->id, 'title' => 'a']);
    AiChatSession::create(['user_id' => $patient->id, 'title' => 'b']);
    AiChatSession::create(['user_id' => $patient->id, 'appointment_id' => $appointment->id, 'title' => 'c']);

    expect(fn () => AiChatSession::create(['user_id' => $patient->id, 'appointment_id' => $appointment->id, 'title' => 'd']))
        ->toThrow(UniqueConstraintViolationException::class);
});
