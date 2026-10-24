<?php

use App\Models\Prescription;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Audit P10: a prescription stops being "active" once its course has ended.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->doctor = makeDoctor();
    $this->patient = makePatient();
});

function prescriptionIssuedAt(string $dhakaTime, array $durations, $doctor, $patient): Prescription
{
    $p = Prescription::create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'diagnosis_summary' => 'Test',
        'status' => 'active',
    ]);
    $p->forceFill(['created_at' => Carbon::parse($dhakaTime, 'Asia/Dhaka')->utc()])->save();
    foreach ($durations as $days) {
        $p->items()->create(['medicine_name' => 'Napa', 'dosage' => '500mg', 'duration_days' => $days, 'dosage_schedule' => ['morning' => true]]);
    }

    return $p;
}

it('completes a course whose last day has passed, using the longest medicine', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dhaka'));

    // Issued 20 Oct, longest 3 days -> last day 22 Oct: ended.
    $ended = prescriptionIssuedAt('2026-10-20 10:00', [1, 3], $this->doctor, $this->patient);
    // Issued 20 Oct, 5 days -> last day 24 Oct (today): still running.
    $running = prescriptionIssuedAt('2026-10-20 10:00', [5], $this->doctor, $this->patient);

    $this->artisan('prescriptions:complete-ended')->assertSuccessful();

    expect($ended->fresh()->status)->toBe('completed')
        ->and($running->fresh()->status)->toBe('active');
});

it('uses the Dhaka date, not the UTC date', function () {
    // 00:30 Dhaka on 23 Oct is still 22 Oct in UTC; a 1-day course ends on 23 Oct Dhaka.
    $p = prescriptionIssuedAt('2026-10-23 00:30', [1], $this->doctor, $this->patient);

    Carbon::setTestNow(Carbon::parse('2026-10-23 23:00', 'Asia/Dhaka'));
    $this->artisan('prescriptions:complete-ended');
    expect($p->fresh()->status)->toBe('active');

    Carbon::setTestNow(Carbon::parse('2026-10-24 00:20', 'Asia/Dhaka'));
    $this->artisan('prescriptions:complete-ended');
    expect($p->fresh()->status)->toBe('completed');
});

it('leaves cancelled prescriptions alone', function () {
    Carbon::setTestNow(Carbon::parse('2026-10-24 09:00', 'Asia/Dhaka'));
    $p = prescriptionIssuedAt('2026-09-01 10:00', [1], $this->doctor, $this->patient);
    $p->update(['status' => 'cancelled']);

    $this->artisan('prescriptions:complete-ended');

    expect($p->fresh()->status)->toBe('cancelled');
});

it('is scheduled daily', function () {
    $events = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->filter(fn ($e) => str_contains($e->command ?? '', 'prescriptions:complete-ended'));

    expect($events)->toHaveCount(1);
});
