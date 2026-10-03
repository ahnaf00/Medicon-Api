<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('starts offline and persists the toggle', function () {
    $doctor = makeDoctor();

    $this->actingAs($doctor, 'sanctum')
        ->getJson('/api/v1/doctor/presence')
        ->assertOk()
        ->assertJsonPath('isOnline', false);

    $this->postJson('/api/v1/doctor/presence', ['is_online' => true])
        ->assertOk()
        ->assertJsonPath('isOnline', true);

    $profile = $doctor->doctorProfile->fresh();
    expect($profile->is_online)->toBeTrue()
        ->and($profile->last_seen_at)->not->toBeNull();

    $this->getJson('/api/v1/doctor/presence')->assertJsonPath('isOnline', true);

    $this->postJson('/api/v1/doctor/presence', ['is_online' => false])
        ->assertJsonPath('isOnline', false);
});

it('exposes isOnline on the public doctor profile', function () {
    $doctor = makeDoctor();
    $doctor->doctorProfile->update(['is_online' => true, 'last_seen_at' => now()]);

    $this->getJson("/api/v1/doctors/{$doctor->id}")
        ->assertOk()
        ->assertJsonFragment(['isOnline' => true]);
});

it('lapses to offline when the app stops checking in', function () {
    $doctor = makeDoctor();

    $this->actingAs($doctor, 'sanctum')
        ->postJson('/api/v1/doctor/presence', ['is_online' => true])
        ->assertJsonPath('isOnline', true);

    $this->travel(4)->minutes();
    $this->getJson('/api/v1/doctor/presence')->assertJsonPath('isOnline', true);

    $this->travel(2)->minutes();
    $this->getJson('/api/v1/doctor/presence')->assertJsonPath('isOnline', false);
    $this->getJson("/api/v1/doctors/{$doctor->id}")->assertJsonFragment(['isOnline' => false]);

    // Turning the toggle on again brings the doctor back online.
    $this->postJson('/api/v1/doctor/presence', ['is_online' => true])
        ->assertJsonPath('isOnline', true);
});

it('keeps an online doctor online with heartbeats', function () {
    $doctor = makeDoctor();

    $this->actingAs($doctor, 'sanctum')
        ->postJson('/api/v1/doctor/presence', ['is_online' => true]);

    foreach (range(1, 3) as $_) {
        $this->travel(4)->minutes();
        $this->postJson('/api/v1/doctor/presence/heartbeat')->assertOk()->assertJsonPath('isOnline', true);
    }
});

it('never switches a doctor on from a heartbeat', function () {
    $doctor = makeDoctor();

    $this->actingAs($doctor, 'sanctum')
        ->postJson('/api/v1/doctor/presence', ['is_online' => false]);

    // e.g. a beat sent just before "go offline" that arrives just after it
    $this->postJson('/api/v1/doctor/presence/heartbeat')
        ->assertOk()
        ->assertJsonPath('isOnline', false);

    expect($doctor->doctorProfile->fresh()->is_online)->toBeFalse();
});

it('requires a boolean', function () {
    $this->actingAs(makeDoctor(), 'sanctum')
        ->postJson('/api/v1/doctor/presence', [])
        ->assertUnprocessable();
});

it('refuses patients and unverified doctors', function () {
    $this->actingAs(makePatient(), 'sanctum')
        ->postJson('/api/v1/doctor/presence', ['is_online' => true])
        ->assertForbidden();

    $this->actingAs(makeDoctor('pending'), 'sanctum')
        ->postJson('/api/v1/doctor/presence', ['is_online' => true])
        ->assertForbidden();
});
