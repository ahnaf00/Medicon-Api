<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

// Audit F11: the email/phone + password endpoint the app does not use yet.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
    $this->patient->update(['password' => Hash::make('right-password-1')]);
});

function passwordLogin(string $login, string $password)
{
    return test()->postJson('/api/v1/auth/login', ['login' => $login, 'password' => $password]);
}

it('signs a patient in with the right password', function () {
    passwordLogin($this->patient->email, 'right-password-1')->assertOk()->assertJsonStructure(['access_token']);
});

it('locks the account for 15 minutes after 5 wrong passwords', function () {
    foreach (range(1, 5) as $_) {
        passwordLogin($this->patient->email, 'wrong')->assertUnprocessable();
    }

    // Even the right password is refused while locked.
    passwordLogin($this->patient->email, 'right-password-1')->assertStatus(429);

    $this->travel(16)->minutes();
    passwordLogin($this->patient->email, 'right-password-1')->assertOk();
});

it('answers an unknown account exactly like a wrong password', function () {
    passwordLogin('nobody@example.com', 'whatever')
        ->assertUnprocessable()
        ->assertJsonPath('errors.login.0', 'The provided credentials are incorrect.');
    passwordLogin($this->patient->email, 'wrong')
        ->assertJsonPath('errors.login.0', 'The provided credentials are incorrect.');
});

it('does not let doctors or admins sign in with a password alone', function () {
    $admin = User::factory()->create(['password' => Hash::make('admin-pass-1')]);
    $admin->assignRole('admin');
    $doctor = makeDoctor();
    $doctor->update(['password' => Hash::make('doctor-pass-1')]);

    passwordLogin($doctor->email, 'doctor-pass-1')->assertForbidden()->assertJsonMissingPath('access_token');
    passwordLogin($admin->email, 'admin-pass-1')->assertForbidden();
    expect($doctor->tokens()->count() + $admin->tokens()->count())->toBe(0);
});

it('never gives test accounts a known password', function () {
    $user = User::factory()->create();
    expect(Hash::check('password123', $user->password))->toBeFalse();
});
