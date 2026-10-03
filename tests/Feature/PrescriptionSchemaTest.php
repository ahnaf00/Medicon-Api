<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('returns ordered tests in order', function () {
    $prescription = Prescription::factory()->create();
    $prescription->tests()->create(['name' => 'ECG', 'order' => 1]);
    $prescription->tests()->create(['name' => 'CBC', 'order' => 0]);

    expect($prescription->tests()->pluck('name')->all())->toBe(['CBC', 'ECG']);
});

it('renames stored afternoon dose keys to noon', function () {
    $legacy = PrescriptionItem::factory()->create([
        'dosage_schedule' => ['morning' => '08:00', 'afternoon' => '14:00'],
    ]);
    $current = PrescriptionItem::factory()->create([
        'dosage_schedule' => ['morning' => '08:00', 'night' => '20:00'],
    ]);

    (require database_path('migrations/2026_10_03_100005_rename_afternoon_dose_key_to_noon.php'))->up();

    expect($legacy->fresh()->dosage_schedule)->toBe(['morning' => '08:00', 'noon' => '14:00'])
        ->and($current->fresh()->dosage_schedule)->toBe(['morning' => '08:00', 'night' => '20:00']);
});
