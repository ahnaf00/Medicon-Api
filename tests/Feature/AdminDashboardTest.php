<?php

use App\Models\AdminAuditLog;
use App\Models\Appointment;
use App\Models\ConsultationTranscript;
use App\Models\User;
use App\Services\AccountDeletionService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit L2b step 1: admin Overview, Users (suspend / reactivate) and the audit log.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['status' => 'active', 'name' => 'Admin One']);
    $this->admin->assignRole('admin');
});

function admin($test)
{
    return $test->actingAs($test->admin, 'sanctum');
}

it('keeps everyone but admins out of the dashboard', function () {
    $target = makePatient();

    foreach ([makePatient(), makeDoctor()] as $user) {
        $as = fn () => $this->actingAs($user, 'sanctum');
        $as()->getJson('/api/v1/admin/overview')->assertForbidden();
        $as()->getJson('/api/v1/admin/users')->assertForbidden();
        $as()->getJson("/api/v1/admin/users/{$target->id}")->assertForbidden();
        $as()->postJson("/api/v1/admin/users/{$target->id}/suspend", ['reason' => 'Testing access'])->assertForbidden();
        $as()->postJson("/api/v1/admin/users/{$target->id}/reactivate")->assertForbidden();
        $as()->getJson('/api/v1/admin/audit-log')->assertForbidden();
    }

    expect($target->fresh()->status)->toBe('active');
});

it('counts users, doctors, today\'s visits by Dhaka day and failed transcripts', function () {
    // 2026-10-25 01:00 in Dhaka is 2026-10-24 19:00 UTC.
    $this->travelTo(\Illuminate\Support\Carbon::parse('2026-10-25 06:00', 'UTC'));
    $patient = makePatient();
    $doctor = makeDoctor();
    makeDoctor('pending');
    $doctor->doctorProfile->forceFill(['is_online' => true])->save();

    $visit = fn (string $utc, string $status) => Appointment::factory()->create([
        'patient_user_id' => $patient->id,
        'doctor_user_id' => $doctor->id,
        'appointment_datetime' => \Illuminate\Support\Carbon::parse($utc, 'UTC'),
        'status' => $status,
    ]);
    $visit('2026-10-24 19:00', 'completed');   // today in Dhaka
    $visit('2026-10-25 10:00', 'scheduled');   // today in Dhaka
    $visit('2026-10-24 17:59', 'completed');   // yesterday in Dhaka
    $failed = $visit('2026-10-20 10:00', 'completed');
    ConsultationTranscript::create(['appointment_id' => $failed->id, 'status' => 'failed']);

    $res = admin($this)->getJson('/api/v1/admin/overview')->assertOk();

    $res->assertJsonPath('users.patients', 1)
        ->assertJsonPath('users.doctors', 2)
        ->assertJsonPath('doctors.pending', 1)
        ->assertJsonPath('doctors.verified', 1)
        ->assertJsonPath('doctors.onlineNow', 1)
        ->assertJsonPath('visitsToday.total', 2)
        ->assertJsonPath('visitsToday.completed', 1)
        ->assertJsonPath('visitsToday.scheduled', 1)
        ->assertJsonPath('transcripts.failed', 1)
        ->assertJsonCount(14, 'visitsByDay')
        ->assertJsonPath('visitsByDay.13', ['date' => '2026-10-25', 'total' => 2])
        ->assertJsonPath('visitsByDay.12', ['date' => '2026-10-24', 'total' => 1]);

    // Counts only: no names leave this endpoint.
    expect($res->getContent())->not->toContain($patient->name)->not->toContain($doctor->name);
});

it('finds people by name or by phone typed with or without +880', function () {
    $rahat = makePatient();
    $rahat->update(['name' => 'Rahat Ahmed', 'phone' => '+8801811000001']);
    makePatient()->update(['name' => 'Someone Else', 'phone' => '+8801999000002']);

    foreach (['rahat', '01811000001', '+8801811000001', '1811000'] as $q) {
        admin($this)->getJson('/api/v1/admin/users?q='.urlencode($q))
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.id', $rahat->id);
    }

    admin($this)->getJson('/api/v1/admin/users?role=doctor')->assertOk()->assertJsonCount(0, 'data');
});

it('shows a person\'s record without any medical content', function () {
    $patient = makePatient();
    $doctor = makeDoctor();
    bookAppointment($patient, $doctor);

    $data = admin($this)->getJson("/api/v1/admin/users/{$patient->id}")
        ->assertOk()
        ->assertJsonPath('data.role', 'patient')
        ->assertJsonPath('data.visits.total', 1)
        ->assertJsonPath('data.visits.upcoming', 1)
        ->json('data');

    expect(array_keys($data))->toEqualCanonicalizing([
        'id', 'name', 'phone', 'email', 'role', 'status', 'joinedAt', 'lastActiveAt', 'deletedAt',
        'suspension', 'purgeAt', 'visits', 'doctor',
    ]);
});

it('suspends with a reason: signs out, cancels visits, tells the other person, logs it', function () {
    $doctor = makeDoctor();
    $doctor->doctorProfile->forceFill(['is_online' => true])->save();
    $doctor->createToken('phone');
    $patient = makePatient();
    $visit = bookAppointment($patient, $doctor);

    admin($this)->postJson("/api/v1/admin/users/{$doctor->id}/suspend", ['reason' => ''])->assertUnprocessable();
    admin($this)->postJson("/api/v1/admin/users/{$doctor->id}/suspend", ['reason' => 'Fake BMDC number reported'])
        ->assertOk();

    $doctor->refresh();
    expect($doctor->status)->toBe('suspended')
        ->and($doctor->suspension_reason)->toBe('Fake BMDC number reported')
        ->and($doctor->suspended_by_user_id)->toBe($this->admin->id)
        ->and($doctor->tokens()->count())->toBe(0)
        ->and($doctor->doctorProfile->is_online)->toBeFalse()
        ->and($visit->fresh()->status)->toBe('cancelled');

    $notice = $patient->notifications()->first();
    expect($notice->data['kind'])->toBe('visit_cancelled')
        ->and($notice->data['body'])->not->toContain('suspend');

    // Any token they still hold stops working.
    $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/notifications')->assertForbidden();

    $log = AdminAuditLog::sole();
    expect($log->action)->toBe(AdminAuditLog::USER_SUSPENDED)
        ->and($log->target_id)->toBe($doctor->id)
        ->and($log->reason)->toBe('Fake BMDC number reported')
        ->and($log->meta['cancelled_visits'])->toBe(1);

    admin($this)->getJson("/api/v1/admin/users/{$doctor->id}")
        ->assertJsonPath('data.suspension.reason', 'Fake BMDC number reported')
        ->assertJsonPath('data.suspension.by', 'Admin One');
});

it('refuses to suspend yourself, another admin, or someone already suspended', function () {
    $other = User::factory()->create();
    $other->assignRole('admin');
    $patient = makePatient();
    $patient->update(['status' => 'suspended']);

    admin($this)->postJson("/api/v1/admin/users/{$this->admin->id}/suspend", ['reason' => 'Just testing'])->assertUnprocessable();
    admin($this)->postJson("/api/v1/admin/users/{$other->id}/suspend", ['reason' => 'Just testing'])->assertForbidden();
    admin($this)->postJson("/api/v1/admin/users/{$patient->id}/suspend", ['reason' => 'Just testing'])->assertConflict();

    expect(AdminAuditLog::count())->toBe(0);
});

it('reactivates: back to active, or to pending for a doctor who was never approved', function () {
    $patient = makePatient();
    $unapproved = makeDoctor('pending');
    foreach ([$patient, $unapproved] as $u) {
        admin($this)->postJson("/api/v1/admin/users/{$u->id}/suspend", ['reason' => 'Under review'])->assertOk();
    }

    admin($this)->postJson("/api/v1/admin/users/{$patient->id}/reactivate")->assertOk();
    admin($this)->postJson("/api/v1/admin/users/{$unapproved->id}/reactivate")->assertOk();
    admin($this)->postJson("/api/v1/admin/users/{$patient->id}/reactivate")->assertConflict();

    expect($patient->fresh()->status)->toBe('active')
        ->and($patient->fresh()->suspension_reason)->toBeNull()
        ->and($unapproved->fresh()->status)->toBe('pending')
        ->and($patient->notifications()->first()->data['title'])->toBe('Your account is active again');

    $this->actingAs($patient->fresh(), 'sanctum')->getJson('/api/v1/notifications')->assertOk();
    expect(AdminAuditLog::where('action', AdminAuditLog::USER_REACTIVATED)->count())->toBe(2);
});

it('logs doctor approvals and lists every action newest first, append-only', function () {
    $pending = makeDoctor('pending');
    $patient = makePatient();

    admin($this)->postJson("/api/v1/admin/doctors/{$pending->id}/approve")->assertOk();
    admin($this)->postJson("/api/v1/admin/users/{$patient->id}/suspend", ['reason' => 'Abusive messages'])->assertOk();

    admin($this)->getJson('/api/v1/admin/audit-log')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.action', 'user.suspended')
        ->assertJsonPath('data.0.admin', 'Admin One')
        ->assertJsonPath('data.0.target.id', $patient->id)
        ->assertJsonPath('data.0.reason', 'Abusive messages')
        ->assertJsonPath('data.1.action', 'doctor.approved');

    admin($this)->getJson("/api/v1/admin/audit-log?target_id={$pending->id}")->assertJsonCount(1, 'data');

    $row = AdminAuditLog::first();
    expect(fn () => $row->update(['reason' => 'changed']))->toThrow(LogicException::class)
        ->and(fn () => $row->delete())->toThrow(LogicException::class);
});

it('shows a purged account as "Deleted user" in the log and list', function () {
    $patient = makePatient();
    admin($this)->postJson("/api/v1/admin/users/{$patient->id}/suspend", ['reason' => 'Spam account'])->assertOk();

    $patient->delete();
    app(AccountDeletionService::class)->purge($patient);

    expect($patient->fresh()->suspension_reason)->toBeNull();
    admin($this)->getJson('/api/v1/admin/audit-log')
        ->assertJsonPath('data.0.target.name', AccountDeletionService::DELETED_NAME);
});
