<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit F12: the login number changes only with a code sent to the new number.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.otp.demo_mode' => true]);

    $this->patient = makePatient();
    $this->patient->update(['phone' => '+8801811000001']);
});

it('no longer lets the profile form change the number', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->putJson('/api/v1/user/me', ['phone' => '01911000002'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('phone');

    expect($this->patient->fresh()->phone)->toBe('+8801811000001');
});

it('still saves the profile when the app resends the current number', function (string $current) {
    $this->actingAs($this->patient, 'sanctum')
        ->putJson('/api/v1/user/me', ['name' => 'Rahat A.', 'phone' => $current])
        ->assertOk();

    expect($this->patient->fresh()->name)->toBe('Rahat A.');
})->with(['+8801811000001', '01811000001']);

it('changes the number once the code sent to it is entered', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/user/me/phone/send-otp', ['phone' => '01911000002'])
        ->assertOk();

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/user/me/phone/verify', ['phone' => '01911000002', 'otp' => '123456'])
        ->assertOk()
        ->assertJsonPath('user.phone', '+8801911000002');

    expect($this->patient->fresh()->phone)->toBe('+8801911000002');
});

it('keeps the old number when the code is wrong', function () {
    $this->actingAs($this->patient, 'sanctum')->postJson('/api/v1/user/me/phone/send-otp', ['phone' => '01911000002']);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/user/me/phone/verify', ['phone' => '01911000002', 'otp' => '999999'])
        ->assertStatus(400)
        ->assertJsonPath('message', 'Invalid OTP.');

    expect($this->patient->fresh()->phone)->toBe('+8801811000001');
});

it('needs a code that was sent to that number', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/user/me/phone/verify', ['phone' => '01911000002', 'otp' => '123456'])
        ->assertStatus(400);

    expect($this->patient->fresh()->phone)->toBe('+8801811000001');
});

it('refuses a number another account uses, or the current one', function (string $phone, string $message) {
    makeDoctor()->update(['phone' => '+8801711000001']);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/user/me/phone/send-otp', ['phone' => $phone])
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', $message);
})->with([
    'taken' => ['01711000001', 'This number is already used by another account.'],
    'own' => ['01811000001', 'This is already your number.'],
]);

it('requires login', function () {
    $this->postJson('/api/v1/user/me/phone/send-otp', ['phone' => '01911000002'])->assertUnauthorized();
});
