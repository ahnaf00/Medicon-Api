<?php

use App\Models\MedicalRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

function geminiReportReply(array $payload): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => json_encode($payload)]]]]]];
}

function cbcPayload(array $overrides = []): array
{
    return $overrides + [
        'title' => 'HAEMATOLOGY - CBC',
        'laboratory_name' => 'LABAID LTD. (DIAGNOSTICS)',
        'report_date' => '2026-08-13',
        'summary' => 'Most values are within range. MPV is slightly high at 12.7 fL.',
        'results' => [
            [
                'panel' => 'Complete Blood Count', 'sub_group' => 'Red Blood Cells', 'name' => 'Haemoglobin',
                'value' => '12.1', 'unit' => 'g/dL', 'reference_text' => 'F 11.5-15.5, M 13.8-18.0',
                'reference_low' => null, 'reference_high' => null, 'status' => 'normal',
            ],
            [
                'panel' => 'Complete Blood Count', 'sub_group' => 'Platelets', 'name' => 'MPV',
                'value' => '12.7', 'unit' => 'fL', 'reference_text' => '7.5-11.5',
                // The model says normal; the numeric range says high. The range wins.
                'reference_low' => 7.5, 'reference_high' => 11.5, 'status' => 'normal',
            ],
            [
                'panel' => 'Complete Blood Count', 'sub_group' => null, 'name' => 'ESR',
                'value' => '30', 'unit' => 'mm/hr', 'reference_text' => null,
                'reference_low' => null, 'reference_high' => null, 'status' => 'made-up',
            ],
        ],
    ];
}

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();

    $this->patient = makePatient();
    $id = $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', [
            'files' => [
                UploadedFile::fake()->create('p1.jpg', 20, 'image/jpeg'),
                UploadedFile::fake()->create('p2.pdf', 20, 'application/pdf'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->json('record.id');
    $this->record = MedicalRecord::findOrFail($id);
    $this->pagePaths = $this->record->pages()->pluck('file_path')->all();
});

function analyze($test)
{
    return $test->actingAs($test->patient, 'sanctum')
        ->postJson("/api/v1/medical-records/{$test->record->id}/analyze");
}

it('extracts lab results, metadata and a summary', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReportReply(cbcPayload()))]);

    analyze($this)->assertStatus(202);

    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/medical-records/{$this->record->id}")
        ->assertOk()
        ->assertJsonPath('record.analysisStatus', 'completed')
        ->assertJsonPath('record.analysisError', null)
        ->assertJsonPath('record.title', 'HAEMATOLOGY - CBC')
        ->assertJsonPath('record.laboratoryName', 'LABAID LTD. (DIAGNOSTICS)')
        ->assertJsonPath('record.reportDate', '2026-08-13')
        ->assertJsonPath('record.aiSummary', 'Most values are within range. MPV is slightly high at 12.7 fL.')
        ->assertJsonCount(3, 'record.labResults')
        ->assertJsonPath('record.labResults.0.referenceText', 'F 11.5-15.5, M 13.8-18.0')
        ->assertJsonPath('record.labResults.0.status', 'normal')
        ->assertJsonPath('record.labResults.1.status', 'high')
        ->assertJsonPath('record.labResults.2.status', 'unknown')
        ->assertJsonPath('record.labResults.2.subGroup', null);

    // Every page went to the model, with the key in a header rather than the URL.
    Http::assertSent(function (Request $request) {
        $parts = $request['contents'][0]['parts'];

        return $request->hasHeader('x-goog-api-key', 'test-key')
            && ! str_contains($request->url(), 'key=')
            && $parts[0]['inline_data']['mime_type'] === 'image/jpeg'
            && $parts[1]['inline_data']['mime_type'] === 'application/pdf'
            && $request['generationConfig']['responseMimeType'] === 'application/json';
    });
    foreach ($this->pagePaths as $path) {
        Storage::disk('private')->assertExists($path);
    }
});

it('replaces earlier results when a report is analysed again', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReportReply(cbcPayload()))]);
    analyze($this);
    analyze($this);

    expect($this->record->labResults()->count())->toBe(3);
});

it('drops an invalid or future report date and keeps existing metadata', function ($date) {
    $this->record->forceFill(['title' => 'My own title'])->save();
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(
        geminiReportReply(cbcPayload(['report_date' => $date, 'title' => null]))
    )]);

    analyze($this);

    $fresh = $this->record->fresh();
    expect($fresh->report_date)->toBeNull()
        ->and($fresh->title)->toBe('My own title')
        ->and($fresh->analysis_status)->toBe('completed');
})->with(['not a date' => 'last Tuesday', 'impossible date' => '2026-02-30', 'future date' => '2099-01-01']);

it('marks the analysis failed but keeps the upload when the model fails', function ($response) {
    Http::fake(['generativelanguage.googleapis.com/*' => $response]);

    analyze($this)->assertStatus(202);

    $fresh = $this->record->fresh();
    expect($fresh->analysis_status)->toBe('failed')
        ->and($fresh->analysis_error)->not->toBeNull()
        ->and($fresh->labResults()->count())->toBe(0)
        ->and($fresh->pages()->count())->toBe(2);
    foreach ($this->pagePaths as $path) {
        Storage::disk('private')->assertExists($path);
    }
})->with([
    'server error' => fn () => Http::response('boom', 500),
    'connection failure' => fn () => Http::failedConnection(),
    'malformed JSON' => fn () => Http::response(['candidates' => [['content' => ['parts' => [['text' => 'not json']]]]]]),
    'nothing extracted' => fn () => Http::response(geminiReportReply(['summary' => '', 'results' => []])),
]);

it('labels analysis unavailable without calling the model when no API key is set', function () {
    config(['services.gemini.api_key' => null]);
    Http::fake();

    analyze($this)->assertStatus(202);

    expect($this->record->fresh())
        ->analysis_status->toBe('failed')
        ->analysis_error->toBe('Automatic report analysis is not available right now.');
    Http::assertNothingSent();
});

it('does not queue a second run while one is in progress', function () {
    Http::fake();
    $this->record->forceFill(['analysis_status' => 'processing'])->save();

    analyze($this)->assertStatus(202)->assertJsonPath('record.analysisStatus', 'processing');

    Http::assertNothingSent();
});

it('retries a run that has been stuck in processing', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiReportReply(cbcPayload()))]);
    MedicalRecord::whereKey($this->record->id)->update([
        'analysis_status' => 'processing',
        'updated_at' => now()->subMinutes(10),
    ]);

    analyze($this)->assertStatus(202);

    expect($this->record->fresh()->analysis_status)->toBe('completed');
});

it("forbids analysing another patient's record", function () {
    Http::fake();

    $this->actingAs(makePatient(), 'sanctum')
        ->postJson("/api/v1/medical-records/{$this->record->id}/analyze")
        ->assertForbidden();

    expect($this->record->fresh()->analysis_status)->toBe('pending');
    Http::assertNothingSent();
});
