<?php

use App\Models\Appointment;
use App\Models\MedicalRecord;
use App\Models\Prescription;
use App\Models\User;
use App\Models\Vital;
use App\Services\PhoneOtpService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Audit L3: soft-delete an account, 30-day restore window, then purge; data export.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    $this->patient = makePatient();
    $this->doctor = makeDoctor();
});

function deleteAccount($user, string $otp = PhoneOtpService::DEMO_OTP)
{
    test()->actingAs($user, 'sanctum')->postJson('/api/v1/user/me/deletion-code')->assertOk();

    return test()->actingAs($user, 'sanctum')->deleteJson('/api/v1/user/me', ['otp' => $otp]);
}

function signInByCode(string $phone)
{
    test()->postJson('/api/v1/auth/send-otp', ['phone' => $phone]);

    return test()->postJson('/api/v1/auth/verify-otp', ['phone' => $phone, 'otp' => PhoneOtpService::DEMO_OTP])->assertOk();
}

it('needs the code sent to the account phone', function () {
    $this->actingAs($this->patient, 'sanctum')->postJson('/api/v1/user/me/deletion-code')->assertOk();
    $this->actingAs($this->patient, 'sanctum')->deleteJson('/api/v1/user/me', ['otp' => '000000'])->assertStatus(400);
    expect($this->patient->fresh()->trashed())->toBeFalse();
});

it('hides the account, signs it out and cancels upcoming visits', function () {
    $visit = bookAppointment($this->patient, $this->doctor);
    $visit->update(['appointment_datetime' => now()->addDay()]);
    $this->patient->createToken('phone');

    deleteAccount($this->patient)->assertOk()->assertJsonStructure(['purgeAt']);

    expect(User::find($this->patient->id))->toBeNull()
        ->and($this->patient->tokens()->count())->toBe(0)
        ->and($visit->fresh()->status)->toBe('cancelled');
    $this->actingAs($this->doctor, 'sanctum')->getJson('/api/v1/notifications')
        ->assertJsonPath('data.0.kind', 'visit_cancelled');

    // The doctor's history still shows who the visit was with.
    expect($visit->fresh()->patient?->name)->toBe($this->patient->name);
});

it('takes a deleted doctor out of search', function () {
    $this->getJson('/api/v1/doctors')->assertJsonFragment(['id' => $this->doctor->id]);
    deleteAccount($this->doctor)->assertOk();
    $this->getJson('/api/v1/doctors')->assertJsonMissing(['id' => $this->doctor->id]);
});

it('lets the person restore within the grace period', function () {
    deleteAccount($this->patient)->assertOk();

    $body = signInByCode($this->patient->phone)
        ->assertJsonPath('accountDeleted', true)
        ->assertJsonMissingPath('access_token');

    $this->postJson('/api/v1/auth/restore-account', ['restore_token' => $body->json('restore_token')])
        ->assertOk()
        ->assertJsonStructure(['access_token', 'user' => ['id', 'role']]);

    expect(User::find($this->patient->id))->not->toBeNull();
    // The token is single use.
    $this->postJson('/api/v1/auth/restore-account', ['restore_token' => $body->json('restore_token')])->assertStatus(422);
});

it('removes personal data after 30 days but keeps clinical records without the name', function () {
    $phone = $this->patient->phone;
    $record = MedicalRecord::factory()->create(['patient_user_id' => $this->patient->id, 'file_path' => 'records/a.jpg']);
    Storage::disk('private')->put('records/a.jpg', 'x');
    Vital::factory()->create(['user_id' => $this->patient->id]);
    $visit = bookAppointment($this->patient, $this->doctor);
    $visit->update(['status' => 'completed', 'appointment_datetime' => now()->subWeek()]);
    $rx = Prescription::factory()->create(['patient_user_id' => $this->patient->id, 'doctor_user_id' => $this->doctor->id]);

    deleteAccount($this->patient)->assertOk();

    $this->travel(29)->days();
    $this->artisan('accounts:purge')->assertSuccessful();
    expect(User::withTrashed()->find($this->patient->id)->purged_at)->toBeNull();

    $this->travel(2)->days();
    $this->artisan('accounts:purge')->assertSuccessful();

    $gone = User::withTrashed()->find($this->patient->id);
    expect($gone->name)->toBe('Deleted user')
        ->and($gone->phone)->toBeNull()
        ->and($gone->email)->toBeNull()
        ->and(MedicalRecord::find($record->id))->toBeNull()
        ->and(Vital::where('user_id', $this->patient->id)->count())->toBe(0)
        ->and(Appointment::find($visit->id))->not->toBeNull()
        ->and(Prescription::find($rx->id)?->patient?->name)->toBe('Deleted user');
    Storage::disk('private')->assertMissing('records/a.jpg');

    // The number is free again: signing in with it starts a new registration.
    signInByCode($phone)->assertJsonPath('isNewUser', true);
});

it('does not let an admin delete itself in the app', function () {
    $admin = User::factory()->create();
    $admin->assignRole('admin');
    $this->actingAs($admin, 'sanctum')->postJson('/api/v1/user/me/deletion-code')->assertForbidden();
});

it('exports the person\'s own data as a file', function () {
    MedicalRecord::factory()->create(['patient_user_id' => $this->patient->id, 'file_path' => 'records/secret.jpg']);
    Vital::factory()->create(['user_id' => $this->patient->id]);
    MedicalRecord::factory()->create(['patient_user_id' => makePatient()->id]);

    $response = $this->actingAs($this->patient, 'sanctum')->get('/api/v1/user/me/export')->assertOk();
    expect($response->headers->get('content-disposition'))->toContain('attachment');

    $data = json_decode($response->streamedContent(), true);
    expect($data['account']['id'])->toBe($this->patient->id)
        ->and($data['medical_reports'])->toHaveCount(1)
        ->and($data['vitals'])->toHaveCount(1)
        ->and($response->streamedContent())->not->toContain('records/secret.jpg');
});
