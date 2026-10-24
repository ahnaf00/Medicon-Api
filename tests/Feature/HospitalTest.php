<?php

use App\Models\Hospital;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
});

it('lists hospitals with only the fields the server stores', function () {
    Hospital::factory()->create(['name' => 'Alpha Hospital', 'is_24_7' => false]);

    $this->actingAs($this->patient, 'sanctum')
        ->getJson('/api/v1/hospitals')
        ->assertOk()
        ->assertJsonPath('data.0.name', 'Alpha Hospital')
        ->assertJsonPath('data.0.is247', false)
        ->assertJsonStructure(['data' => [['id', 'name', 'address', 'latitude', 'longitude', 'emergencyPhone', 'is247']]]);
});

it('searches by name or address', function () {
    Hospital::factory()->create(['name' => 'Alpha Hospital', 'address' => 'Road 1, Dhaka']);
    Hospital::factory()->create(['name' => 'Beta Clinic', 'address' => 'Mirpur, Dhaka']);

    $names = fn (string $q) => collect(
        $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/hospitals?search=' . $q)->json('data')
    )->pluck('name')->all();

    expect($names('alpha'))->toBe(['Alpha Hospital'])
        ->and($names('mirpur'))->toBe(['Beta Clinic']);
});

it('makes factory hospitals that cannot be mistaken for real ones', function () {
    $hospital = Hospital::factory()->create();

    expect($hospital->name)->toContain('(test data)')
        ->and($hospital->emergency_phone)->toBe('+880 0000-000000');
});
