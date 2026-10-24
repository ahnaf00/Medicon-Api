<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\RateLimiter;

uses(RefreshDatabase::class);

// Audit F3 / SEC-005: OTP sends and checks are limited per phone, not only per IP.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.otp.demo_mode' => true]);
});

function fromIp(string $ip)
{
    return test()->withServerVariables(['REMOTE_ADDR' => $ip]);
}

it('allows three codes per phone in ten minutes, from any IP', function () {
    foreach (['10.0.0.1', '10.0.0.2', '10.0.0.3'] as $ip) {
        fromIp($ip)->postJson('/api/v1/auth/send-otp', ['phone' => '+8801711999001'])->assertOk();
    }

    fromIp('10.0.0.4')->postJson('/api/v1/auth/send-otp', ['phone' => '+880 1711-999001'])
        ->assertStatus(429)
        ->assertJsonPath('message', 'Too many codes requested for this number. Try again in a few minutes.');

    // Another number is unaffected.
    fromIp('10.0.0.4')->postJson('/api/v1/auth/send-otp', ['phone' => '+8801711999002'])->assertOk();
});

it('limits code checks per phone across IPs', function () {
    fromIp('10.0.1.1')->postJson('/api/v1/auth/send-otp', ['phone' => '+8801711999003'])->assertOk();

    $limited = 'Too many attempts for this number. Try again in a few minutes.';
    $messages = collect(range(1, 11))->map(fn ($i) => fromIp("10.0.2.$i")
        ->postJson('/api/v1/auth/verify-otp', ['phone' => '+8801711999003', 'otp' => '000000'])
        ->json('message'));

    // The first 10 reach the code check (wrong code / too many tries for this code);
    // the 11th is stopped by the per-phone limit.
    expect($messages->take(10)->contains($limited))->toBeFalse()
        ->and($messages->last())->toBe($limited);
});

it('allows the code again after ten minutes', function () {
    foreach (range(1, 3) as $i) {
        fromIp("10.0.3.$i")->postJson('/api/v1/auth/send-otp', ['phone' => '+8801711999004'])->assertOk();
    }
    $this->travel(11)->minutes();

    fromIp('10.0.3.9')->postJson('/api/v1/auth/send-otp', ['phone' => '+8801711999004'])->assertOk();
});
