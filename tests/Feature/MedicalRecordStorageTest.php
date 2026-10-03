<?php

use App\Models\MedicalRecord;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Use the real private disk (not Storage::fake, which stubs temporaryUrl) rooted
    // in a throwaway directory, so the actual signed-URL route is exercised.
    $this->privateRoot = storage_path('framework/testing/disks/private-'.uniqid());
    config(['filesystems.disks.private.root' => $this->privateRoot]);
    Storage::forgetDisk('private');
    Storage::fake('public');

    $this->patient = makePatient();
});

afterEach(function () {
    \Illuminate\Support\Facades\File::deleteDirectory($this->privateRoot);
});

function uploadRecord($test): MedicalRecord
{
    $test->actingAs($test->patient, 'sanctum')
        ->post('/api/v1/medical-records', [
            'file' => UploadedFile::fake()->create('cbc.pdf', 120, 'application/pdf'),
        ], ['Accept' => 'application/json'])
        ->assertCreated();

    return MedicalRecord::latest('id')->firstOrFail();
}

it('stores uploads on the private disk, never the public one', function () {
    $record = uploadRecord($this);

    expect($record->file_path)->toStartWith('medical-records/');
    expect($record->file_url)->toBeNull();
    Storage::disk('private')->assertExists($record->file_path);
    Storage::disk('public')->assertMissing($record->file_path);
});

it('returns a signed, expiring URL that serves the file', function () {
    $record = uploadRecord($this);

    $url = $this->actingAs($this->patient, 'sanctum')
        ->getJson("/api/v1/medical-records/{$record->id}")
        ->assertOk()
        ->json('record.fileUrl');

    expect($url)->toContain('/private-files/')->toContain('signature=')->toContain('expires=');

    $this->get($url)->assertOk();
});

it('rejects requests for the file without a valid signature', function () {
    $record = uploadRecord($this);

    $this->get("/private-files/{$record->file_path}")->assertForbidden();
    $this->get("/private-files/{$record->file_path}?expires=9999999999&signature=forged")->assertForbidden();
});

it("forbids reading or deleting another patient's record", function () {
    $record = uploadRecord($this);
    $intruder = makePatient();

    $this->actingAs($intruder, 'sanctum')
        ->getJson("/api/v1/medical-records/{$record->id}")
        ->assertForbidden();

    $this->actingAs($intruder, 'sanctum')
        ->deleteJson("/api/v1/medical-records/{$record->id}")
        ->assertForbidden();

    Storage::disk('private')->assertExists($record->file_path);
});

it('deletes the stored file along with the record', function () {
    $record = uploadRecord($this);

    $this->actingAs($this->patient, 'sanctum')
        ->deleteJson("/api/v1/medical-records/{$record->id}")
        ->assertOk();

    Storage::disk('private')->assertMissing($record->file_path);
    expect(MedicalRecord::find($record->id))->toBeNull();
});
