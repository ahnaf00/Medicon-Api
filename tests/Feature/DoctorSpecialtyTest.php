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

it('only lets a doctor sign up with a listed specialty', function () {
    $this->postJson('/api/v1/auth/register', [
        'name' => 'Dr. Free Text',
        'phone' => '+8801700000002',
        'role' => 'doctor',
        'registration_token' => verifyNewPhone('+8801700000002'),
        'specialty' => 'Cardiologist',
        'qualification' => 'MBBS',
    ])->assertUnprocessable()->assertJsonValidationErrors('specialty');
});

it('never exposes a doctor\'s email, phone or account status publicly', function () {
    $doctor = makeDoctor('verified', 'Cardiology');

    $list = $this->getJson('/api/v1/doctors')->assertOk()->json('data.0');
    $one = $this->getJson("/api/v1/doctors/{$doctor->id}")->assertOk()->json('data');

    foreach ([$list, $one] as $body) {
        expect($body)->toHaveKeys(['id', 'name', 'doctorProfile', 'avatarUrl'])
            ->and($body)->not->toHaveKeys(['email', 'phone', 'status']);
    }
});
