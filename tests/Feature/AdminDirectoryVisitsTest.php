<?php

use App\Models\Appointment;
use App\Models\ConsultationTranscript;
use App\Models\Prescription;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;

uses(RefreshDatabase::class);

// Audit L2b step 2: the admin doctors directory and the view-only Visits tab.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
});

function asAdminUser($test)
{
    return $test->actingAs($test->admin, 'sanctum');
}

function visitAt(User $patient, User $doctor, string $utc, string $status = 'scheduled', array $extra = []): Appointment
{
    return Appointment::factory()->create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'appointment_datetime' => Carbon::parse($utc, 'UTC'),
        'status' => $status,
    ] + $extra);
}

it('keeps everyone but admins out of the directory and visits', function () {
    $visit = bookAppointment(makePatient(), makeDoctor());

    foreach ([makePatient(), makeDoctor()] as $user) {
        $as = fn () => $this->actingAs($user, 'sanctum');
        $as()->getJson('/api/v1/admin/doctors?status=all')->assertForbidden();
        $as()->getJson('/api/v1/admin/visits')->assertForbidden();
        $as()->getJson("/api/v1/admin/visits/{$visit->id}")->assertForbidden();
    }
});

it('lists every doctor A-Z with online state and visit counts, searchable', function () {
    $patient = makePatient();
    $zed = makeDoctor('verified', 'Cardiology');
    $zed->update(['name' => 'Zed Karim']);
    $zed->doctorProfile->forceFill(['is_online' => true, 'bmdc_registration_no' => 'A-55555'])->save();
    $amy = makeDoctor('pending', 'Dermatology');
    $amy->update(['name' => 'Amy Rahman']);
    visitAt($patient, $zed, '2026-10-01 05:00', 'completed');
    visitAt($patient, $zed, '2030-01-01 05:00', 'scheduled');

    asAdminUser($this)->getJson('/api/v1/admin/doctors?status=all')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.name', 'Amy Rahman')
        ->assertJsonPath('data.0.verificationStatus', 'pending')
        ->assertJsonPath('data.1.isOnline', true)
        ->assertJsonPath('data.1.accountStatus', 'active')
        ->assertJsonPath('data.1.visits', ['completed' => 1, 'upcoming' => 1]);

    foreach (['derma', 'A-5555', 'amy'] as $q) {
        $expected = $q === 'A-5555' ? 'Zed Karim' : 'Amy Rahman';
        asAdminUser($this)->getJson('/api/v1/admin/doctors?status=all&q='.urlencode($q))
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.name', $expected);
    }

    asAdminUser($this)->getJson('/api/v1/admin/doctors?status=all&online=1')
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Zed Karim');

    // The review queue still defaults to pending only.
    asAdminUser($this)->getJson('/api/v1/admin/doctors')->assertJsonCount(1, 'data');
});

it('lists visits for a Dhaka day, in time order, filtered by status', function () {
    // 2026-10-25 in Dhaka is 2026-10-24 18:00 to 2026-10-25 17:59:59 UTC.
    $this->travelTo(Carbon::parse('2026-10-25 06:00', 'UTC'));
    $patient = makePatient();
    $doctor = makeDoctor();
    $late = visitAt($patient, $doctor, '2026-10-25 17:59', 'scheduled');
    $early = visitAt($patient, $doctor, '2026-10-24 18:00', 'completed');
    visitAt($patient, $doctor, '2026-10-24 17:59', 'completed');   // the day before
    visitAt($patient, $doctor, '2026-10-25 18:00', 'scheduled');   // the day after

    asAdminUser($this)->getJson('/api/v1/admin/visits')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.id', $early->id)
        ->assertJsonPath('data.1.id', $late->id)
        ->assertJsonPath('data.0.patient.name', $patient->name)
        ->assertJsonPath('data.0.doctor.specialty', 'Cardiology');

    asAdminUser($this)->getJson('/api/v1/admin/visits?status=completed')->assertJsonCount(1, 'data');
    asAdminUser($this)->getJson('/api/v1/admin/visits?from=2026-10-24&to=2026-10-26')->assertJsonCount(4, 'data');
    asAdminUser($this)->getJson('/api/v1/admin/visits?from=2026-10-01&to=2026-11-15')->assertUnprocessable();
    asAdminUser($this)->getJson('/api/v1/admin/visits?from=2026-10-26&to=2026-10-25')->assertUnprocessable();
});

it('shows a visit\'s record without its medical content', function () {
    $patient = makePatient();
    $doctor = makeDoctor();
    $visit = visitAt($patient, $doctor, '2026-10-20 05:00', 'completed', [
        'notes' => 'PRIVATE-REASON chest pain at night',
        'started_at' => Carbon::parse('2026-10-20 05:02', 'UTC'),
        'ended_at' => Carbon::parse('2026-10-20 05:20', 'UTC'),
    ]);
    Prescription::factory()->create([
        'appointment_id' => $visit->id,
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'diagnosis_summary' => 'PRIVATE-DIAGNOSIS angina',
    ]);
    ConsultationTranscript::create(['appointment_id' => $visit->id, 'status' => 'ready']);

    $res = asAdminUser($this)->getJson("/api/v1/admin/visits/{$visit->id}")
        ->assertOk()
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.hasPrescription', true)
        ->assertJsonPath('data.transcriptStatus', 'ready')
        ->assertJsonPath('data.startedAt', '2026-10-20T05:02:00+00:00');

    expect($res->getContent())->not->toContain('PRIVATE-');

    $bare = bookAppointment($patient, $doctor);
    asAdminUser($this)->getJson("/api/v1/admin/visits/{$bare->id}")
        ->assertJsonPath('data.hasPrescription', false)
        ->assertJsonPath('data.transcriptStatus', null);
});
