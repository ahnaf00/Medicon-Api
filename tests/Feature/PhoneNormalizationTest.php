<?php

use App\Models\User;
use App\Support\PhoneNumber;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

// Audit F13: one stored form, +8801XXXXXXXXX, whatever the user types.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.otp.demo_mode' => true]);
});

it('converts the usual ways of writing a Bangladeshi mobile number', function (?string $input, ?string $expected) {
    expect(PhoneNumber::normalize($input))->toBe($expected);
})->with([
    'local' => ['01811000001', '+8801811000001'],
    'already stored form' => ['+8801811000001', '+8801811000001'],
    'country code without +' => ['8801811000001', '+8801811000001'],
    'spaces and dashes' => ['+880 1811-000001', '+8801811000001'],
    'without the leading 0' => ['1811000001', '+8801811000001'],
    'not a mobile operator code' => ['01211000001', null],
    'too short' => ['0181100000', null],
    'foreign number' => ['+447911123456', null],
    'empty' => [null, null],
]);

it('logs an existing user in whichever format the number is typed in', function (string $typed) {
    $patient = makePatient();
    $patient->update(['phone' => '+8801811000001']);

    $this->postJson('/api/v1/auth/send-otp', ['phone' => $typed])->assertOk();
    $this->postJson('/api/v1/auth/verify-otp', ['phone' => $typed, 'otp' => '123456'])
        ->assertOk()
        ->assertJsonPath('isNewUser', false)
        ->assertJsonPath('user.id', $patient->id);
})->with(['01811000001', '8801811000001', '+880 1811-000001']);

it('stores the +880 form at sign-up and refuses a second account in another format', function () {
    $token = verifyNewPhone('01811000002');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'New Patient',
        'phone' => '01811000002',
        'role' => 'patient',
        'registration_token' => $token,
    ])->assertCreated()->assertJsonPath('user.phone', '+8801811000002');

    $this->postJson('/api/v1/auth/register', [
        'name' => 'Same Person',
        'phone' => '+880 1811-000002',
        'role' => 'patient',
        'registration_token' => 'anything',
    ])->assertUnprocessable()->assertJsonValidationErrors('phone');

    expect(User::where('phone', '+8801811000002')->count())->toBe(1);
});

it('refuses a number that is not a Bangladeshi mobile', function () {
    $this->postJson('/api/v1/auth/send-otp', ['phone' => '1234567890'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.phone.0', PhoneNumber::INVALID_MESSAGE);
});

it('stores the +880 form when a user changes their number', function () {
    $patient = makePatient();

    $this->actingAs($patient, 'sanctum')->postJson('/api/v1/user/me/phone/send-otp', ['phone' => '01911000003'])->assertOk();
    $this->actingAs($patient, 'sanctum')
        ->postJson('/api/v1/user/me/phone/verify', ['phone' => '01911000003', 'otp' => '123456'])
        ->assertOk();

    expect($patient->fresh()->phone)->toBe('+8801911000003');
});

it('converts existing numbers but leaves clashes and invalid ones for the owner', function () {
    $stored = User::factory()->create(['phone' => '+8801711000001']);
    $local = User::factory()->create(['phone' => '01811000004']);
    $clash = User::factory()->create(['phone' => '01711000001']);
    $invalid = User::factory()->create(['phone' => '1234567890']);
    DB::table('phone_otps')->insert([
        ['phone' => '01811000004', 'otp' => 'x', 'attempts' => 0, 'expires_at' => now()],
        ['phone' => '+8801811000005', 'otp' => 'x', 'attempts' => 0, 'expires_at' => now()],
    ]);

    $migration = require database_path('migrations/2026_10_25_000002_normalize_user_phones.php');
    ob_start();
    $migration->up();
    ob_end_clean();

    expect($stored->fresh()->phone)->toBe('+8801711000001')
        ->and($local->fresh()->phone)->toBe('+8801811000004')
        ->and($clash->fresh()->phone)->toBe('01711000001')
        ->and($invalid->fresh()->phone)->toBe('1234567890')
        ->and(DB::table('phone_otps')->pluck('phone')->all())->toBe(['+8801811000005']);
});
