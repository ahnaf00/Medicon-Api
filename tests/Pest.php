<?php

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/*
|--------------------------------------------------------------------------
| Test Case
|--------------------------------------------------------------------------
|
| The closure you provide to your test functions is always bound to a specific PHPUnit test
| case class. By default, that class is "PHPUnit\Framework\TestCase". Of course, you may
| need to change it using the "pest()" function to bind different classes or traits.
|
*/

pest()->extend(TestCase::class)
 // ->use(RefreshDatabase::class)
    ->in('Feature');

/*
|--------------------------------------------------------------------------
| Expectations
|--------------------------------------------------------------------------
|
| When you're writing tests, you often need to check that values meet certain conditions. The
| "expect()" function gives you access to a set of "expectations" methods that you can use
| to assert different things. Of course, you may extend the Expectation API at any time.
|
*/

expect()->extend('toBeOne', function () {
    return $this->toBe(1);
});

/*
|--------------------------------------------------------------------------
| Functions
|--------------------------------------------------------------------------
|
| While Pest is very powerful out-of-the-box, you may have some testing code specific to your
| project that you don't want to repeat in every file. Here you can also expose helpers as
| global functions to help you to reduce the number of lines of code in your test files.
|
*/

function something()
{
    // ..
}

function makePatient(array $profile = []): \App\Models\User
{
    $user = \App\Models\User::factory()->create();
    $user->assignRole('patient');
    \App\Models\PatientProfile::factory()->create(['user_id' => $user->id] + $profile);

    return $user;
}

function makeDoctor(string $verification = 'verified', string $specialty = 'Cardiology'): \App\Models\User
{
    $user = \App\Models\User::factory()->create();
    $user->assignRole('doctor');
    \App\Models\DoctorProfile::factory()->create([
        'user_id' => $user->id,
        'specialty' => $specialty,
        'verification_status' => $verification,
    ]);

    return $user;
}

function bookAppointment(\App\Models\User $patient, \App\Models\User $doctor): \App\Models\Appointment
{
    return \App\Models\Appointment::factory()->create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'status' => 'scheduled',
    ]);
}
