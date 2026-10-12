<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.otp.demo_mode' => true]);
});

function patientPayload(string $phone, ?string $proof = null): array
{
    return array_filter([
        'name' => 'Test Patient',
        'phone' => $phone,
        'role' => 'patient',
        'registration_token' => $proof,
    ]);
}

// --- OTP demo mode ---

it('accepts the demo code 123456 and says no SMS was sent', function () {
    $this->postJson('/api/v1/auth/send-otp', ['phone' => '01700000001'])
        ->assertOk()
        ->assertJsonPath('demo', true);
});

it('never writes the OTP to the log', function () {
    Log::spy();

    $this->postJson('/api/v1/auth/send-otp', ['phone' => '01700000001'])->assertOk();

    Log::shouldNotHaveReceived('info');
});

it('refuses to send an OTP when demo mode is off, because nothing can deliver it', function () {
    config(['services.otp.demo_mode' => false]);

    $this->postJson('/api/v1/auth/send-otp', ['phone' => '01700000001'])->assertStatus(503);
});

it('logs an existing user in with the demo code', function () {
    $patient = makePatient();
    $patient->update(['phone' => '01700000002']);

    $this->postJson('/api/v1/auth/send-otp', ['phone' => '01700000002'])->assertOk();

    $this->postJson('/api/v1/auth/verify-otp', ['phone' => '01700000002', 'otp' => '123456'])
        ->assertOk()
        ->assertJsonPath('isNewUser', false)
        ->assertJsonStructure(['access_token']);
});

// --- Registration needs proof of phone ownership ---

it('rejects registration without an OTP proof', function () {
    $this->postJson('/api/v1/auth/register', patientPayload('01700000003'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_token');

    expect(User::where('phone', '01700000003')->exists())->toBeFalse();
});

it('rejects registration with a made-up proof', function () {
    verifyNewPhone('01700000003');

    $this->postJson('/api/v1/auth/register', patientPayload('01700000003', 'not-the-proof'))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_token');
});

it('registers with the proof issued for that phone', function () {
    $proof = verifyNewPhone('01700000003');

    $this->postJson('/api/v1/auth/register', patientPayload('01700000003', $proof))
        ->assertCreated()
        ->assertJsonStructure(['access_token']);
});

it('rejects a proof issued for a different phone', function () {
    $proof = verifyNewPhone('01700000003');

    $this->postJson('/api/v1/auth/register', patientPayload('01700000004', $proof))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_token');
});

it('consumes the proof, so it cannot be used twice', function () {
    $proof = verifyNewPhone('01700000003');
    $this->postJson('/api/v1/auth/register', patientPayload('01700000003', $proof))->assertCreated();

    User::where('phone', '01700000003')->delete();

    $this->postJson('/api/v1/auth/register', patientPayload('01700000003', $proof))
        ->assertStatus(422)
        ->assertJsonValidationErrors('registration_token');
});

// --- Sessions ---

it('answers 403 when a suspended user logs in with a password', function () {
    $patient = makePatient();
    $patient->update(['status' => 'suspended', 'password' => Hash::make('secret123')]);

    $this->postJson('/api/v1/auth/login', ['login' => $patient->email, 'password' => 'secret123'])
        ->assertStatus(403);
});

it('blocks a suspended user who still holds a token', function () {
    $patient = makePatient();
    $token = $patient->createToken('auth_token')->plainTextToken;

    $this->withToken($token)->getJson('/api/v1/user/me')->assertOk();

    $patient->update(['status' => 'suspended']);
    $this->app['auth']->forgetGuards();

    $this->withToken($token)->getJson('/api/v1/user/me')->assertStatus(403);
});

it('does not block a pending doctor from their own account', function () {
    $doctor = makeDoctor('pending');
    $doctor->update(['status' => 'pending']);

    $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/user/me')->assertOk();
});

it('rejects a token older than the configured lifetime', function () {
    $patient = makePatient();
    $token = $patient->createToken('auth_token')->plainTextToken;

    $this->travel(31)->days();

    $this->withToken($token)->getJson('/api/v1/user/me')->assertUnauthorized();
});
