<?php

use App\Models\DoctorAvailability;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit F4 / SEC-010: the weekly schedule is validated and errors leak nothing.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->doctor = makeDoctor();
});

function saveSchedule($doctor, array $schedule)
{
    return test()->actingAs($doctor, 'sanctum')->putJson('/api/v1/doctor/availability', ['schedule' => $schedule]);
}

it('saves a valid week', function () {
    saveSchedule($this->doctor, [
        ['day' => 'Sunday', 'startTime' => '09:00 AM', 'endTime' => '05:00 PM', 'isWorkingDay' => true],
        ['day' => 'Friday', 'startTime' => '09:00 AM', 'endTime' => '09:00 AM', 'isWorkingDay' => false],
    ])->assertOk();

    $sunday = DoctorAvailability::where('doctor_user_id', $this->doctor->id)->where('day_of_week', 0)->first();
    expect($sunday->start_time)->toStartWith('09:00')->and($sunday->end_time)->toStartWith('17:00');
});

it('rejects a time in the wrong format', function () {
    saveSchedule($this->doctor, [
        ['day' => 'Sunday', 'startTime' => '25:99', 'endTime' => '05:00 PM', 'isWorkingDay' => true],
    ])->assertUnprocessable()->assertJsonValidationErrors('schedule.0.startTime');
});

it('rejects an unknown day', function () {
    saveSchedule($this->doctor, [
        ['day' => 'Funday', 'startTime' => '09:00 AM', 'endTime' => '05:00 PM', 'isWorkingDay' => true],
    ])->assertUnprocessable()->assertJsonValidationErrors('schedule.0.day');
});

it('rejects a working day that ends before it starts', function () {
    saveSchedule($this->doctor, [
        ['day' => 'Monday', 'startTime' => '05:00 PM', 'endTime' => '09:00 AM', 'isWorkingDay' => true],
    ])->assertUnprocessable()->assertJsonValidationErrors('schedule.0.endTime');

    expect(DoctorAvailability::count())->toBe(0);
});
