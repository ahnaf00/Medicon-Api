<?php

use App\Http\Resources\PrescriptionDocumentResource;
use App\Models\Prescription;
use App\Services\AllergyCheck;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

// Audit G1 / CLIN-005, remaining parts: dose amounts + route, allergy check,
// cancel (void) and reissue.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    $this->doctor = makeDoctor();
    $this->patient = makePatient(['allergies' => 'Penicillin (rash), Sulfa drugs']);
    $this->appointment = bookAppointment($this->patient, $this->doctor);
    $this->appointment->update(['status' => 'completed']);
});

function safetyPayload($test, array $medicine = [], array $overrides = []): array
{
    return $overrides + [
        'patient_user_id' => $test->patient->id,
        'appointment_id' => $test->appointment->id,
        'diagnosis_summary' => 'Viral fever',
        'medicines' => [$medicine + [
            'medicine_name' => 'Paracetamol',
            'dosage' => '500mg',
            'dosage_schedule' => ['morning' => '08:00', 'night' => '20:00'],
            'duration_days' => 5,
        ]],
    ];
}

// ── Dose amounts and route ────────────────────────────────────────────────────

it('stores how much to take at each time and the route, and prints 2+0+1', function () {
    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', safetyPayload($this, [
            'dose_quantities' => ['morning' => 2, 'night' => 1],
            'route' => 'oral',
        ]))
        ->assertCreated();

    $prescription = Prescription::sole();
    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$prescription->id}")
        ->assertOk()
        ->assertJsonPath('data.medicines.0.dosePattern', '2+0+1')
        ->assertJsonPath('data.medicines.0.route', 'oral')
        ->assertJsonPath('data.medicines.0.doseQuantities.morning', 2);

    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$prescription->id}/document")
        ->assertOk()
        ->assertJsonPath('data.medicines.0.pattern', '2+0+1')
        ->assertJsonPath('data.medicines.0.route', 'oral');
});

it('prints fractions and treats a slot without an amount as 1', function (array $schedule, ?array $quantities, string $pattern) {
    expect(PrescriptionDocumentResource::dosePattern($schedule, $quantities))->toBe($pattern);
})->with([
    'half tablets' => [['morning' => '08:00', 'night' => '20:00'], ['morning' => 0.5, 'night' => 0.5], '½+0+½'],
    'one and a half' => [['morning' => '08:00'], ['morning' => 1.5], '1½+0+0'],
    'older prescription' => [['morning' => '08:00', 'noon' => '14:00'], null, '1+1+0'],
    'quarter' => [['night' => '20:00'], ['night' => 0.25], '0+0+¼'],
]);

it('refuses an amount for a time that is not scheduled, an unknown route, or a silly amount', function (array $medicine, string $field) {
    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', safetyPayload($this, $medicine))
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(Prescription::count())->toBe(0);
})->with([
    'noon amount, no noon time' => [['dose_quantities' => ['noon' => 1]], 'medicines.0.dose_quantities.noon'],
    'unknown slot' => [['dose_quantities' => ['evening' => 1]], 'medicines.0.dose_quantities'],
    'unknown route' => [['route' => 'by magic'], 'medicines.0.route'],
    'too much' => [['dose_quantities' => ['morning' => 40]], 'medicines.0.dose_quantities.morning'],
]);

// ── Allergy check ─────────────────────────────────────────────────────────────

it('stops a medicine whose name matches a recorded allergy until the doctor confirms', function () {
    $payload = safetyPayload($this, ['medicine_name' => 'Penicillin V', 'dosage' => '250mg']);

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', $payload)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('allergy_acknowledged')
        ->assertJsonPath('allergyConflicts.0.medicine', 'Penicillin V')
        ->assertJsonPath('allergyConflicts.0.allergy', 'penicillin');
    expect(Prescription::count())->toBe(0);

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', $payload + ['allergy_acknowledged' => true])
        ->assertCreated();

    $prescription = Prescription::sole();
    expect($prescription->allergy_conflicts)->toBe([['medicine' => 'Penicillin V', 'allergy' => 'penicillin']])
        ->and($prescription->allergy_acknowledged_at)->not->toBeNull();

    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$prescription->id}")
        ->assertJsonPath('data.allergyConflicts.0.allergy', 'penicillin');
});

it('lets unrelated medicines through without asking', function () {
    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', safetyPayload($this))
        ->assertCreated()
        ->assertJsonPath('prescription.allergy_conflicts', null);
});

it('matches allergy names, not drug classes, and ignores "none"', function (?string $allergies, array $medicines, array $expected) {
    expect(array_column(AllergyCheck::conflicts($allergies, $medicines), 'allergy'))->toBe($expected);
})->with([
    'whole word in medicine' => ['Penicillin', ['Penicillin V Potassium'], ['penicillin']],
    'medicine name inside the allergy text' => ['allergic to ibuprofen tablets', ['Ibuprofen 400'], ['allergic to ibuprofen tablets']],
    'medicine first word inside allergen' => ['Ibuprofen', ['ibuprofen'], ['ibuprofen']],
    'not a drug-class check' => ['Penicillin', ['Amoxicillin'], []],
    'no partial words' => ['ace', ['Paracetamol'], []],
    'none recorded' => ['None', ['Paracetamol'], []],
    'nkda' => ['NKDA', ['Paracetamol'], []],
    'empty' => [null, ['Paracetamol'], []],
    'semicolons and and' => ['Aspirin; codeine and latex', ['Codeine Phosphate'], ['codeine']],
]);

// ── Cancel (void) and reissue ─────────────────────────────────────────────────

it('lets the prescribing doctor cancel with a reason, kept in the history', function () {
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', safetyPayload($this))->assertCreated();
    $prescription = Prescription::sole();

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson("/api/v1/prescriptions/{$prescription->id}/void", ['reason' => 'Wrong strength written'])
        ->assertOk()
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.voidReason', 'Wrong strength written');

    $prescription->refresh();
    expect($prescription->appointment_id)->toBeNull()
        ->and($prescription->voided_appointment_id)->toBe($this->appointment->id)
        ->and($prescription->voided_by_user_id)->toBe($this->doctor->id);

    // Still in the patient's list, marked cancelled.
    $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/prescriptions')
        ->assertJsonPath('data.0.status', 'cancelled');

    // The document says so too.
    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$prescription->id}/document")
        ->assertJsonPath('data.status', 'cancelled')
        ->assertJsonPath('data.voidReason', 'Wrong strength written');
});

it('refuses cancelling by the patient, another doctor, twice, or without a reason', function () {
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', safetyPayload($this))->assertCreated();
    $id = Prescription::sole()->id;

    $this->actingAs($this->patient, 'sanctum')->postJson("/api/v1/prescriptions/{$id}/void", ['reason' => 'mine'])->assertForbidden();
    $this->actingAs(makeDoctor(), 'sanctum')->postJson("/api/v1/prescriptions/{$id}/void", ['reason' => 'not mine'])->assertForbidden();
    $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$id}/void", [])->assertJsonValidationErrors('reason');

    $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$id}/void", ['reason' => 'Error'])->assertOk();
    $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$id}/void", ['reason' => 'Again'])
        ->assertJsonValidationErrors('reason');
});

it('reissues for the same visit after cancelling, and links the two', function () {
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', safetyPayload($this))->assertCreated();
    $old = Prescription::sole();

    // While it is live, the visit is taken.
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', safetyPayload($this))
        ->assertJsonValidationErrors('appointment_id');

    $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$old->id}/void", ['reason' => 'Wrong dose'])->assertOk();

    $payload = safetyPayload($this, ['dosage' => '650mg'], ['replaces_prescription_id' => $old->id]);
    unset($payload['appointment_id']); // taken from the cancelled one
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', $payload)->assertCreated();

    $new = Prescription::latest('id')->first();
    expect($new->appointment_id)->toBe($this->appointment->id)
        ->and($new->replaces_prescription_id)->toBe($old->id)
        ->and($this->appointment->fresh()->prescription->id)->toBe($new->id);

    $this->actingAs($this->patient, 'sanctum')->getJson("/api/v1/prescriptions/{$old->id}")
        ->assertJsonPath('data.replacedByPrescriptionId', $new->id);

    // Only once.
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', $payload)
        ->assertJsonValidationErrors('replaces_prescription_id');
});

it('refuses to "replace" a live prescription or another doctor\'s', function () {
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', safetyPayload($this))->assertCreated();
    $live = Prescription::sole();

    $payload = safetyPayload($this, [], ['replaces_prescription_id' => $live->id, 'appointment_id' => null]);
    $this->actingAs($this->doctor, 'sanctum')->postJson('/api/v1/prescriptions', $payload)
        ->assertJsonValidationErrors('replaces_prescription_id');

    $this->actingAs($this->doctor, 'sanctum')->postJson("/api/v1/prescriptions/{$live->id}/void", ['reason' => 'Error'])->assertOk();
    $other = makeDoctor();
    $visit = bookAppointment($this->patient, $other);
    $visit->update(['status' => 'completed']);
    $this->actingAs($other, 'sanctum')
        ->postJson('/api/v1/prescriptions', safetyPayload($this, [], ['replaces_prescription_id' => $live->id, 'appointment_id' => $visit->id]))
        ->assertJsonValidationErrors('replaces_prescription_id');
});

it('accepts today\'s Dhaka date as a follow-up just after local midnight', function () {
    $this->travelTo(\Carbon\Carbon::parse('2026-10-24 18:30:00', 'UTC')); // 00:30 on the 25th in Dhaka

    $this->actingAs($this->doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', safetyPayload($this, [], ['follow_up_date' => '2026-10-25']))
        ->assertCreated();
});
