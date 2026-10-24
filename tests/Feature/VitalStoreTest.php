<?php

use App\Models\Vital;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit CLIN-007: only plausible readings are saved.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->patient = makePatient();
});

function storeVital(array $data)
{
    return test()->actingAs(test()->patient, 'sanctum')->postJson('/api/v1/vitals', $data);
}

it('saves a normal reading for the caller', function () {
    storeVital(['blood_pressure' => '120/80', 'pulse_rate' => 72, 'glucose_level' => 5.4, 'oxygen_saturation' => 98])
        ->assertCreated();

    expect(Vital::sole()->user_id)->toBe($this->patient->id);
});

it('saves a single reading on its own', function (array $data) {
    storeVital($data)->assertCreated();
})->with([
    [['blood_pressure' => '182/121']],
    [['pulse_rate' => 45]],
    [['glucose_level' => 2.8]],
    [['oxygen_saturation' => 88]],
]);

it('rejects an empty entry', function () {
    storeVital([])->assertUnprocessable()->assertJsonPath('message', 'Enter at least one reading. (and 3 more errors)');
    expect(Vital::count())->toBe(0);
});

it('rejects impossible blood pressure', function (string $bp, string $message) {
    storeVital(['blood_pressure' => $bp])
        ->assertUnprocessable()
        ->assertJsonPath('errors.blood_pressure.0', $message);
})->with([
    ['80/120', 'The first (systolic) number must be higher than the second (diastolic), e.g. 120/80.'],
    ['120/120', 'The first (systolic) number must be higher than the second (diastolic), e.g. 120/80.'],
    ['300/90', 'Blood pressure must be between 60/30 and 260/160 mmHg.'],
    ['120/20', 'Blood pressure must be between 60/30 and 260/160 mmHg.'],
    ['12080', 'Blood pressure format must be SYS/DIA (e.g., 120/80).'],
]);

it('rejects glucose that is out of range or in mg/dL', function ($value) {
    storeVital(['glucose_level' => $value])
        ->assertUnprocessable()
        ->assertJsonPath('errors.glucose_level.0', 'Enter glucose in mmol/L, between 1 and 35 (100 mg/dL is about 5.6 mmol/L).');
})->with([0, 0.5, 110, 36]);

it('rejects a reading dated in the future', function () {
    storeVital(['pulse_rate' => 70, 'logged_at' => now()->addHour()->toIso8601String()])
        ->assertUnprocessable()
        ->assertJsonPath('errors.logged_at.0', 'A reading cannot be in the future.');
});

it('accepts a reading from earlier today', function () {
    storeVital(['pulse_rate' => 70, 'logged_at' => now()->subHours(3)->toIso8601String()])->assertCreated();
});

it('lists only the caller\'s own readings', function () {
    storeVital(['pulse_rate' => 70])->assertCreated();
    Vital::create(['user_id' => makePatient()->id, 'pulse_rate' => 90]);

    $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/vitals')
        ->assertOk()
        ->assertJsonCount(1, 'data');
});
