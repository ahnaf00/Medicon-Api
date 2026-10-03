<?php

use App\Models\PrescriptionItem;
use App\Services\MedicineExplainerService;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

function geminiReply(string $text): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
}

beforeEach(function () {
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();
});

it('generates and stores an explanation', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReply('Paracetamol relieves pain and fever.'))]);
    $item = PrescriptionItem::factory()->create(['medicine_name' => 'Paracetamol', 'dosage' => '500mg', 'explanation' => null]);

    app(MedicineExplainerService::class)->explain($item);

    expect($item->fresh()->explanation)->toBe('Paracetamol relieves pain and fever.');
    Http::assertSent(fn (Request $request) => $request->hasHeader('x-goog-api-key', 'test-key')
        && ! str_contains($request->url(), 'key='));
});

it('reuses an explanation already written for the same medicine and dosage', function () {
    Http::fake();
    PrescriptionItem::factory()->create(['medicine_name' => 'Napa', 'dosage' => '500mg', 'explanation' => 'Cached text.']);
    $item = PrescriptionItem::factory()->create(['medicine_name' => ' napa ', 'dosage' => '500MG', 'explanation' => null]);

    app(MedicineExplainerService::class)->explain($item);

    expect($item->fresh()->explanation)->toBe('Cached text.');
    Http::assertNothingSent();
});

it('does not reuse an explanation for a different dosage', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReply('Fresh text.'))]);
    PrescriptionItem::factory()->create(['medicine_name' => 'Napa', 'dosage' => '500mg', 'explanation' => 'Cached text.']);
    $item = PrescriptionItem::factory()->create(['medicine_name' => 'Napa', 'dosage' => '1g', 'explanation' => null]);

    app(MedicineExplainerService::class)->explain($item);

    expect($item->fresh()->explanation)->toBe('Fresh text.');
});

it('leaves the explanation empty when the model fails or does not know the medicine', function ($response) {
    Http::fake(['generativelanguage.googleapis.com/*' => $response]);
    $item = PrescriptionItem::factory()->create(['explanation' => null]);

    app(MedicineExplainerService::class)->explain($item);

    expect($item->fresh()->explanation)->toBeNull();
})->with([
    'server error' => fn () => Http::response('boom', 500),
    'unknown medicine' => fn () => Http::response(geminiReply('UNKNOWN')),
    'connection failure' => fn () => Http::failedConnection(),
]);

it('skips the model entirely without an API key', function () {
    config(['services.gemini.api_key' => null]);
    Http::fake();
    $item = PrescriptionItem::factory()->create(['explanation' => null]);

    app(MedicineExplainerService::class)->explain($item);

    expect($item->fresh()->explanation)->toBeNull();
    Http::assertNothingSent();
});

it('explains each medicine after a prescription is issued and returns it on the resource', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    $doctor = makeDoctor();
    $patient = makePatient();
    bookAppointment($patient, $doctor);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReply('Explained.'))]);

    $id = $this->actingAs($doctor, 'sanctum')
        ->postJson('/api/v1/prescriptions', [
            'patient_user_id' => $patient->id,
            'diagnosis_summary' => 'Fever',
            'medicines' => [['medicine_name' => 'Napa', 'dosage' => '500mg', 'duration_days' => 3]],
        ])
        ->assertCreated()
        ->json('prescription.id');

    $this->actingAs($patient, 'sanctum')
        ->getJson("/api/v1/prescriptions/{$id}")
        ->assertJsonPath('data.medicines.0.explanation', 'Explained.');
});
