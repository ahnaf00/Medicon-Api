<?php

use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;

uses(RefreshDatabase::class);

// Audit P12: a transcript must not stay "awaiting the call" after the visit ended.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Queue::fake();
    $this->appointment = bookAppointment(makePatient(), makeDoctor());
    $this->transcript = $this->appointment->transcript()->create([]);
});

it('marks a transcript not recorded when the visit ended over an hour ago with no audio', function () {
    $this->appointment->update(['format' => 'video', 'status' => 'completed', 'ended_at' => now()->subHours(2)]);

    $this->artisan('transcripts:finalize-stale')->assertSuccessful();

    expect($this->transcript->fresh()->status)->toBe('skipped')
        ->and($this->transcript->fresh()->skip_reason)->not->toBeNull();
});

it('finalizes old transcripts whose visit has no end time recorded', function () {
    $this->appointment->update(['format' => 'video', 'status' => 'completed', 'ended_at' => null]);

    $this->artisan('transcripts:finalize-stale');

    expect($this->transcript->fresh()->status)->toBe('skipped');
});

it('leaves a visit that ended recently for the normal upload flow', function () {
    $this->appointment->update(['format' => 'video', 'status' => 'completed', 'ended_at' => now()->subMinutes(10)]);

    $this->artisan('transcripts:finalize-stale');

    expect($this->transcript->fresh()->status)->toBe('awaiting_call');
});

it('leaves a visit that is still in progress', function () {
    $this->appointment->update(['format' => 'video', 'status' => 'in_progress', 'started_at' => now()->subHours(3)]);

    $this->artisan('transcripts:finalize-stale');

    expect($this->transcript->fresh()->status)->toBe('awaiting_call');
});

it('is scheduled hourly', function () {
    $event = collect(app(\Illuminate\Console\Scheduling\Schedule::class)->events())
        ->first(fn ($e) => str_contains($e->command ?? '', 'transcripts:finalize-stale'));

    expect($event?->expression)->toBe('0 * * * *');
});
