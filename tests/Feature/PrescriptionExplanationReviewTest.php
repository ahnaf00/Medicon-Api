<?php

use App\Models\Prescription;
use App\Models\PrescriptionItem;
use App\Services\MedicineExplainerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Audit CLIN-006: the prescribing doctor can rewrite or hide the AI's
// "Why take this medicine?" text, and the patient sees who wrote it.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $this->doctor = makeDoctor();
    $this->patient = makePatient();
    $this->prescription = Prescription::factory()->create([
        'patient_user_id' => $this->patient->id,
        'doctor_user_id' => $this->doctor->id,
        'status' => 'active',
    ]);
    $this->item = PrescriptionItem::factory()->create([
        'prescription_id' => $this->prescription->id,
        'medicine_name' => 'Napa',
        'dosage' => '500mg',
        'explanation' => 'AI text about Napa.',
        'explanation_source' => 'ai',
    ]);
});

function reviewExplanation($user, array $data)
{
    return test()->actingAs($user, 'sanctum')->patchJson(
        '/api/v1/prescriptions/'.test()->prescription->id.'/items/'.test()->item->id.'/explanation',
        $data,
    );
}

function medicineAs($user): array
{
    return test()->actingAs($user, 'sanctum')
        ->getJson('/api/v1/prescriptions/'.test()->prescription->id)
        ->assertOk()
        ->json('data.medicines.0');
}

it('tells the patient an untouched explanation is from the AI', function () {
    expect(medicineAs($this->patient))
        ->explanation->toBe('AI text about Napa.')
        ->explanationSource->toBe('ai')
        ->not->toHaveKey('explanationHidden');
});

it('lets the prescribing doctor rewrite it, which the patient sees as theirs', function () {
    reviewExplanation($this->doctor, ['explanation' => '  Take after food for your fever.  '])->assertOk();

    expect(medicineAs($this->patient))
        ->explanation->toBe('Take after food for your fever.')
        ->explanationSource->toBe('doctor');
});

it('lets the prescribing doctor hide it from the patient, and show it again', function () {
    reviewExplanation($this->doctor, ['hidden' => true])->assertOk();

    expect(medicineAs($this->patient))->explanation->toBeNull()->explanationSource->toBeNull();
    expect(medicineAs($this->doctor))->explanation->toBe('AI text about Napa.')->explanationHidden->toBeTrue();

    reviewExplanation($this->doctor, ['hidden' => false])->assertOk();
    expect(medicineAs($this->patient))->explanation->toBe('AI text about Napa.');
});

it('refuses anyone but the prescribing doctor', function () {
    reviewExplanation(makeDoctor(), ['hidden' => true])->assertForbidden();
    reviewExplanation($this->patient, ['hidden' => true])->assertForbidden();

    expect($this->item->fresh()->explanation_hidden)->toBeFalse();
});

it('only edits an item of this prescription', function () {
    $other = PrescriptionItem::factory()->create(['explanation' => 'x', 'explanation_source' => 'ai']);

    $this->actingAs($this->doctor, 'sanctum')
        ->patchJson("/api/v1/prescriptions/{$this->prescription->id}/items/{$other->id}/explanation", ['hidden' => true])
        ->assertNotFound();
});

it('needs a change to make', function () {
    reviewExplanation($this->doctor, [])->assertUnprocessable();
    reviewExplanation($this->doctor, ['explanation' => ''])->assertUnprocessable();
});

it('never copies a doctor\'s or a hidden explanation to another patient', function (array $change) {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'Fresh AI text.']]]]]])]);
    config(['services.gemini.api_key' => 'test-key']);
    reviewExplanation($this->doctor, $change)->assertOk();

    $next = PrescriptionItem::factory()->create(['medicine_name' => 'Napa', 'dosage' => '500mg', 'explanation' => null]);
    app(MedicineExplainerService::class)->explain($next);

    expect($next->fresh())->explanation->toBe('Fresh AI text.')->explanation_source->toBe('ai');
})->with([
    'rewritten' => [['explanation' => 'Only for this patient.']],
    'hidden' => [['hidden' => true]],
]);
