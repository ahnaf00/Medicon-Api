<?php

use App\Models\Billing;
use Carbon\Carbon;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->doctor = makeDoctor();
    $this->patient = makePatient();
});

afterEach(function () {
    Carbon::setTestNow();
});

/** A paid visit at a UTC instant, billed at that same instant. */
function paidVisitAt($test, string $utc, float $amount): void
{
    $appointment = bookAppointment($test->patient, $test->doctor);
    $appointment->update(['appointment_datetime' => Carbon::parse($utc, 'UTC')]);

    $billing = Billing::create([
        'appointment_id' => $appointment->id,
        'patient_user_id' => $test->patient->id,
        'amount' => $amount,
        'payment_status' => 'paid',
    ]);
    $billing->created_at = Carbon::parse($utc, 'UTC');
    $billing->save();
}

it("counts 'today' by the Dhaka calendar day, not the UTC one", function () {
    // 02:00 on Oct 4 in Dhaka, but still Oct 3 in UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-03 20:00:00', 'UTC'));

    paidVisitAt($this, '2026-10-03 19:00:00', 500); // 01:00 Oct 4 Dhaka → today
    paidVisitAt($this, '2026-10-03 17:00:00', 700); // 23:00 Oct 3 Dhaka → yesterday

    $this->actingAs($this->doctor, 'sanctum')
        ->getJson('/api/v1/doctor/dashboard')
        ->assertOk()
        ->assertJsonPath('today_appointments_count', 1)
        ->assertJsonPath('earnings.today', 500);
});

it("counts 'this month' by the Dhaka calendar month", function () {
    // 01:00 on Nov 1 in Dhaka, but still Oct 31 in UTC.
    Carbon::setTestNow(Carbon::parse('2026-10-31 19:00:00', 'UTC'));

    paidVisitAt($this, '2026-10-31 18:30:00', 500); // 00:30 Nov 1 Dhaka → this month
    paidVisitAt($this, '2026-10-31 17:00:00', 700); // 23:00 Oct 31 Dhaka → previous month

    $this->actingAs($this->doctor, 'sanctum')
        ->getJson('/api/v1/doctor/dashboard')
        ->assertOk()
        ->assertJsonPath('earnings.this_month', 500)
        ->assertJsonPath('earnings.previous_month', 700);
});
