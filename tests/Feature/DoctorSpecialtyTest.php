<?php

use App\Services\SymptomTriageService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit C14: departments come from the server, and filtering by one works.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
});

it('lists every specialty with its count of verified doctors', function () {
    makeDoctor('verified', 'Cardiology');
    makeDoctor('verified', 'Cardiology');
    makeDoctor('pending', 'Cardiology');

    $data = $this->getJson('/api/v1/specialties')->assertOk()->json('data');

    expect(array_column($data, 'name'))->toBe(SymptomTriageService::SPECIALTIES)
        ->and(collect($data)->firstWhere('name', 'Cardiology')['doctorCount'])->toBe(2)
        ->and(collect($data)->firstWhere('name', 'Neurology')['doctorCount'])->toBe(0);
});

it('filters doctors by specialty', function () {
    $cardio = makeDoctor('verified', 'Cardiology');
    makeDoctor('verified', 'Dermatology');

    $ids = collect($this->getJson('/api/v1/doctors?specialty=Cardiology')->assertOk()->json('data'))->pluck('id');

    expect($ids->all())->toBe([$cardio->id]);
});

it('rejects an unknown specialty', function () {
    $this->getJson('/api/v1/doctors?specialty=Astrology')->assertUnprocessable();
});
