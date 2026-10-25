<?php

use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit L2: admins approve or reject doctor registrations in the app.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
    $this->pending = makeDoctor('pending');
    $this->pending->update(['status' => 'pending']);
});

function asAdmin($test)
{
    return $test->actingAs($test->admin, 'sanctum');
}

it('keeps everyone but admins out', function () {
    foreach ([makePatient(), makeDoctor()] as $user) {
        $this->actingAs($user, 'sanctum')->getJson('/api/v1/admin/doctors')->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/doctors/{$this->pending->id}/approve")->assertForbidden();
        $this->actingAs($user, 'sanctum')->postJson("/api/v1/admin/doctors/{$this->pending->id}/reject", ['reason' => 'No good reason'])->assertForbidden();
    }
    expect($this->pending->doctorProfile->fresh()->verification_status)->toBe('pending');
});

it('lists pending doctors with what the admin needs to check', function () {
    makeDoctor('verified');

    asAdmin($this)->getJson('/api/v1/admin/doctors')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->pending->id)
        ->assertJsonPath('data.0.verificationStatus', 'pending')
        ->assertJsonStructure(['data' => [['name', 'phone', 'specialty', 'bmdcRegistrationNo', 'registeredAt']]]);

    asAdmin($this)->getJson('/api/v1/admin/summary')
        ->assertOk()
        ->assertJsonPath('doctors.pending', 1)
        ->assertJsonPath('doctors.verified', 1);
});

it('approves a doctor, who can then use doctor features and is told', function () {
    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/doctor/presence')->assertForbidden();

    asAdmin($this)->postJson("/api/v1/admin/doctors/{$this->pending->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.verificationStatus', 'verified')
        ->assertJsonPath('data.reviewedBy', $this->admin->name);

    expect($this->pending->fresh()->status)->toBe('active');
    $this->actingAs($this->pending->fresh(), 'sanctum')->getJson('/api/v1/doctor/presence')->assertOk();
    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/notifications')
        ->assertJsonPath('data.0.kind', 'account_reviewed')
        ->assertJsonPath('data.0.target.status', 'verified');

    asAdmin($this)->postJson("/api/v1/admin/doctors/{$this->pending->id}/approve")->assertStatus(409);
    asAdmin($this)->postJson("/api/v1/admin/doctors/{$this->pending->id}/reject", ['reason' => 'Changed my mind'])->assertStatus(409);
});

it('rejects with a reason only the doctor sees, and can approve later', function () {
    asAdmin($this)->postJson("/api/v1/admin/doctors/{$this->pending->id}/reject", ['reason' => ''])->assertUnprocessable();
    asAdmin($this)->postJson("/api/v1/admin/doctors/{$this->pending->id}/reject", ['reason' => 'BMDC number not found in the register'])
        ->assertOk()
        ->assertJsonPath('data.verificationStatus', 'rejected');

    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/user/me')
        ->assertJsonPath('data.doctorProfile.rejectionReason', 'BMDC number not found in the register');
    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/doctor/presence')->assertForbidden();
    $this->actingAs($this->pending, 'sanctum')->getJson('/api/v1/notifications')
        ->assertJsonPath('data.0.body', 'Reason: BMDC number not found in the register');

    asAdmin($this)->getJson('/api/v1/admin/doctors?status=rejected')->assertJsonCount(1, 'data');
    asAdmin($this)->postJson("/api/v1/admin/doctors/{$this->pending->id}/approve")->assertOk();
    expect($this->pending->doctorProfile->fresh()->rejection_reason)->toBeNull();
});

it('only touches doctors', function () {
    asAdmin($this)->postJson('/api/v1/admin/doctors/'.makePatient()->id.'/approve')->assertNotFound();
});

describe('admin:make', function () {
    it('creates a separate admin account and refuses a number in use', function () {
        $this->artisan('admin:make', ['phone' => '01712345678', 'name' => 'Clinic Admin'])->assertSuccessful();
        $admin = User::where('phone', '+8801712345678')->first();
        expect($admin->hasRole('admin'))->toBeTrue()->and($admin->getRoleNames()->all())->toBe(['admin']);

        $patient = makePatient();
        $this->artisan('admin:make', ['phone' => $patient->phone ?? '01799999999', 'name' => 'X']);
        expect($patient->fresh()->hasRole('admin'))->toBeFalse();
    });
});
