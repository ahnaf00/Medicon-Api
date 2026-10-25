<?php

use App\Models\AdminAuditLog;
use App\Models\Hospital;
use App\Models\User;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit L2b step 3: admins add, edit, hide and show hospitals. Hiding never deletes.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->admin = User::factory()->create(['status' => 'active']);
    $this->admin->assignRole('admin');
});

function hospitalFields(array $overrides = []): array
{
    return $overrides + [
        'name' => 'Lakeside General Hospital (test data)',
        'address' => '12 Lake Road, Dhaka',
        'emergency_phone' => '+880 2-55165088',
        'is_24_7' => true,
        'latitude' => 23.7806,
        'longitude' => 90.4070,
    ];
}

it('keeps patients and doctors out of hospital management', function () {
    $hospital = Hospital::factory()->create();

    foreach ([makePatient(), makeDoctor()] as $user) {
        $as = fn () => $this->actingAs($user, 'sanctum');
        $as()->getJson('/api/v1/admin/hospitals')->assertForbidden();
        $as()->getJson("/api/v1/admin/hospitals/{$hospital->id}")->assertForbidden();
        $as()->postJson('/api/v1/admin/hospitals', hospitalFields())->assertForbidden();
        $as()->putJson("/api/v1/admin/hospitals/{$hospital->id}", hospitalFields())->assertForbidden();
        $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/deactivate", ['reason' => 'Closed down'])->assertForbidden();
        $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/reactivate")->assertForbidden();
    }

    expect(Hospital::count())->toBe(1)->and($hospital->fresh()->is_active)->toBeTrue();
});

it('adds a hospital patients can see, and logs who added it', function () {
    $id = $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/hospitals', hospitalFields())
        ->assertCreated()
        ->assertJsonPath('data.name', 'Lakeside General Hospital (test data)')
        ->assertJsonPath('data.isActive', true)
        ->assertJsonPath('data.latitude', 23.7806)
        ->json('data.id');

    $this->actingAs(makePatient(), 'sanctum')->getJson('/api/v1/hospitals')
        ->assertJsonPath('data.0.name', 'Lakeside General Hospital (test data)');

    $log = AdminAuditLog::sole();
    expect($log->action)->toBe(AdminAuditLog::HOSPITAL_CREATED)
        ->and($log->target_type)->toBe('hospital')
        ->and($log->target_id)->toBe($id)
        ->and($log->admin_user_id)->toBe($this->admin->id);
});

it('rejects a hospital patients could not rely on', function (array $fields, string $error) {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/hospitals', hospitalFields($fields))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($error);

    expect(Hospital::count())->toBe(0)->and(AdminAuditLog::count())->toBe(0);
})->with([
    'no name' => [['name' => ''], 'name'],
    'no address' => [['address' => ''], 'address'],
    'no phone' => [['emergency_phone' => ''], 'emergency_phone'],
    'letters in phone' => [['emergency_phone' => 'call us'], 'emergency_phone'],
    'latitude without longitude' => [['longitude' => null], 'longitude'],
    'latitude off the map' => [['latitude' => 123], 'latitude'],
    'open hours not said' => [['is_24_7' => null], 'is_24_7'],
]);

it('accepts a short hotline number', function () {
    $this->actingAs($this->admin, 'sanctum')
        ->postJson('/api/v1/admin/hospitals', hospitalFields(['emergency_phone' => '999', 'latitude' => null, 'longitude' => null]))
        ->assertCreated()
        ->assertJsonPath('data.latitude', null);
});

it('refuses the same hospital twice but allows a second branch', function () {
    $as = fn () => $this->actingAs($this->admin, 'sanctum');
    $as()->postJson('/api/v1/admin/hospitals', hospitalFields())->assertCreated();

    $as()->postJson('/api/v1/admin/hospitals', hospitalFields())
        ->assertUnprocessable()
        ->assertJsonPath('errors.name.0', 'A hospital with this name and address already exists.');
    $as()->postJson('/api/v1/admin/hospitals', hospitalFields(['address' => '40 Mirpur Road, Dhaka']))->assertCreated();
});

it('edits a hospital and logs exactly what changed', function () {
    $hospital = Hospital::factory()->create(hospitalFields());

    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/admin/hospitals/{$hospital->id}", hospitalFields(['emergency_phone' => '10666', 'is_24_7' => false]))
        ->assertOk()
        ->assertJsonPath('data.emergencyPhone', '10666')
        ->assertJsonPath('data.is247', false);

    $log = AdminAuditLog::sole();
    expect($log->action)->toBe(AdminAuditLog::HOSPITAL_UPDATED)
        ->and($log->meta['changes'])->toBe([
            'emergency_phone' => ['from' => '+880 2-55165088', 'to' => '10666'],
            'is_24_7' => ['from' => true, 'to' => false],
        ]);

    // Saving the same values again changes nothing and logs nothing.
    $this->actingAs($this->admin, 'sanctum')
        ->putJson("/api/v1/admin/hospitals/{$hospital->id}", hospitalFields(['emergency_phone' => '10666', 'is_24_7' => false]))
        ->assertOk()
        ->assertJsonPath('message', 'Nothing changed.');
    expect(AdminAuditLog::count())->toBe(1);
});

it('hides a hospital from patients without deleting it, and shows it again', function () {
    $hospital = Hospital::factory()->create(hospitalFields());
    $other = Hospital::factory()->create(['name' => 'Other Clinic (test data)', 'address' => '12 Lake Road annex, Dhaka']);
    $patient = makePatient();
    $patientNames = fn (string $search = '') => collect(
        $this->actingAs($patient, 'sanctum')->getJson('/api/v1/hospitals?search='.urlencode($search))->json('data')
    )->pluck('name')->all();
    $as = fn () => $this->actingAs($this->admin, 'sanctum');

    $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/deactivate")
        ->assertUnprocessable()->assertJsonValidationErrors('reason');

    $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/deactivate", ['reason' => 'Closed for rebuilding'])
        ->assertOk()
        ->assertJsonPath('data.isActive', false);

    expect(Hospital::find($hospital->id))->not->toBeNull()
        ->and($hospital->fresh()->deactivated_at)->not->toBeNull()
        ->and($patientNames())->toBe([$other->name])
        // A search that matches the address must not bring a hidden hospital back.
        ->and($patientNames('Lake Road'))->toBe([$other->name]);

    $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/deactivate", ['reason' => 'Again'])->assertStatus(409);

    $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/reactivate")->assertOk()->assertJsonPath('data.isActive', true);
    $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/reactivate")->assertStatus(409);

    expect($patientNames('Lake Road'))->toContain($hospital->name)
        ->and($hospital->fresh()->deactivated_at)->toBeNull()
        ->and(AdminAuditLog::orderBy('id')->pluck('action')->all())
        ->toBe([AdminAuditLog::HOSPITAL_DEACTIVATED, AdminAuditLog::HOSPITAL_REACTIVATED])
        ->and(AdminAuditLog::first()->reason)->toBe('Closed for rebuilding');
});

it('lists every hospital for admins, filtered by shown or hidden and by search', function () {
    Hospital::factory()->create(['name' => 'Beta Clinic (test data)']);
    Hospital::factory()->create(['name' => 'Alpha Hospital (test data)', 'address' => 'Mirpur, Dhaka']);
    $hidden = Hospital::factory()->create(['name' => 'Gamma Hospital (test data)']);
    $hidden->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
    $names = fn (string $query) => collect(
        $this->actingAs($this->admin, 'sanctum')->getJson('/api/v1/admin/hospitals'.$query)->assertOk()->json('data')
    )->pluck('name')->all();

    expect($names(''))->toBe(['Alpha Hospital (test data)', 'Beta Clinic (test data)', 'Gamma Hospital (test data)'])
        ->and($names('?status=hidden'))->toBe(['Gamma Hospital (test data)'])
        ->and($names('?status=active&q=mirpur'))->toBe(['Alpha Hospital (test data)']);
});

it('names hospitals in the audit log alongside accounts', function () {
    $hospital = Hospital::factory()->create(['name' => 'Named Hospital (test data)']);
    $as = fn () => $this->actingAs($this->admin, 'sanctum');
    $as()->postJson("/api/v1/admin/hospitals/{$hospital->id}/deactivate", ['reason' => 'Duplicate entry']);

    $as()->getJson('/api/v1/admin/audit-log')
        ->assertOk()
        ->assertJsonPath('data.0.action', 'hospital.deactivated')
        ->assertJsonPath('data.0.target', ['type' => 'hospital', 'id' => $hospital->id, 'name' => 'Named Hospital (test data)']);

    $as()->getJson("/api/v1/admin/audit-log?target_type=hospital&target_id={$hospital->id}")->assertJsonCount(1, 'data');
    // Without a type the id means an account, as before.
    $as()->getJson("/api/v1/admin/audit-log?target_id={$hospital->id}")->assertJsonCount(0, 'data');
});
