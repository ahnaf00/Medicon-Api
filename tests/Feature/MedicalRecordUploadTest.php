<?php

use App\Models\MedicalRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Storage::fake('private');
    Storage::fake('public');
    $this->patient = makePatient();
});

function pageFiles(int $count): array
{
    return array_map(
        fn ($i) => UploadedFile::fake()->create("page-{$i}.jpg", 50, 'image/jpeg'),
        range(1, $count),
    );
}

it('stores every page privately, in order, and reports the page count', function () {
    $response = $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', [
            'files' => [
                UploadedFile::fake()->create('first.jpg', 50, 'image/jpeg'),
                UploadedFile::fake()->create('second.pdf', 50, 'application/pdf'),
                UploadedFile::fake()->create('third.png', 50, 'image/png'),
            ],
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('record.pageCount', 3)
        ->assertJsonPath('record.analysisStatus', 'pending')
        ->assertJsonPath('record.pages.1.isPdf', true);

    $record = MedicalRecord::findOrFail($response->json('record.id'));
    $paths = $record->pages()->pluck('file_path')->all();

    expect($paths)->toHaveCount(3)
        ->and($record->file_path)->toBe($paths[0])
        ->and($paths[1])->toEndWith('.pdf');
    foreach ($paths as $path) {
        Storage::disk('private')->assertExists($path);
        Storage::disk('public')->assertMissing($path);
    }
});

it('still accepts a single legacy file field as a one-page record', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', [
            'file' => UploadedFile::fake()->create('cbc.pdf', 50, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('record.pageCount', 1);
});

it('rejects more than ten pages', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', ['files' => pageFiles(11)], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files');

    expect(MedicalRecord::count())->toBe(0);
});

it('rejects unsupported page types', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', [
            'files' => [UploadedFile::fake()->create('notes.docx', 10)],
        ], ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('files.0');
});

it('requires at least one file', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/medical-records', [])
        ->assertUnprocessable();
});

it('lists records with page counts and returns pages on the detail endpoint', function () {
    $id = $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', ['files' => pageFiles(2)], ['Accept' => 'application/json'])
        ->json('record.id');

    $this->actingAs($this->patient, 'sanctum')
        ->getJson('/api/v1/medical-records')
        ->assertJsonPath('data.0.pageCount', 2);

    $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/medical-records/{$id}")
        ->assertJsonCount(2, 'record.pages')
        ->assertJsonPath('record.labResults', []);
});

it('deletes every page file along with the record', function () {
    $id = $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/medical-records', ['files' => pageFiles(3)], ['Accept' => 'application/json'])
        ->json('record.id');
    $paths = MedicalRecord::findOrFail($id)->pages()->pluck('file_path')->all();

    $this->actingAs($this->patient, 'sanctum')
        ->deleteJson("/api/v1/medical-records/{$id}")
        ->assertOk();

    foreach ($paths as $path) {
        Storage::disk('private')->assertMissing($path);
    }
});
