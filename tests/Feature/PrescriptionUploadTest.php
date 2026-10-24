<?php

use App\Models\PrescriptionUpload;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;

uses(RefreshDatabase::class);

// Task 1.7 (audit D1): a patient uploads a paper prescription; only they can see it.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);

    // Real private disk in a throwaway folder, so signed URLs are exercised for real.
    $this->privateRoot = storage_path('framework/testing/disks/private-'.uniqid());
    config(['filesystems.disks.private.root' => $this->privateRoot]);
    Storage::forgetDisk('private');
    Storage::fake('public');

    $this->patient = makePatient();
});

afterEach(function () {
    File::deleteDirectory($this->privateRoot);
});

// The CI/dev PHP has no GD, so build JPEG uploads without drawing an image.
function fakeJpeg(string $name): UploadedFile
{
    return UploadedFile::fake()->create($name, 50, 'image/jpeg');
}

function uploadPaperPrescription($test, array $extra = []): \Illuminate\Testing\TestResponse
{
    return $test->actingAs($test->patient, 'sanctum')->post('/api/v1/prescription-uploads', $extra + [
        'files' => [
            fakeJpeg('page1.jpg'),
            fakeJpeg('page2.jpg'),
        ],
        'title' => 'Fever prescription',
        'doctor_name' => 'Dr. Outside Clinic',
        'issued_on' => now()->subDays(3)->format('Y-m-d'),
    ], ['Accept' => 'application/json']);
}

it('stores a two-page upload on the private disk and returns signed page URLs', function () {
    $response = uploadPaperPrescription($this)
        ->assertCreated()
        ->assertJsonPath('upload.title', 'Fever prescription')
        ->assertJsonPath('upload.doctorName', 'Dr. Outside Clinic')
        ->assertJsonPath('upload.pageCount', 2);

    $upload = PrescriptionUpload::with('pages')->sole();
    foreach ($upload->pages as $page) {
        expect($page->file_path)->toStartWith("prescription-uploads/{$this->patient->id}/");
        Storage::disk('private')->assertExists($page->file_path);
        Storage::disk('public')->assertMissing($page->file_path);
    }

    $url = $response->json('upload.pages.0.fileUrl');
    expect($url)->toContain('signature=')->toContain('expires=');
    $this->get($url)->assertOk();
});

it('lists only the patient\'s own uploads, newest first', function () {
    uploadPaperPrescription($this, ['title' => 'Older']);
    $this->travel(1)->minutes();
    uploadPaperPrescription($this, ['title' => 'Newer']);

    $other = makePatient();
    $this->actingAs($other, 'sanctum')->getJson('/api/v1/prescription-uploads')
        ->assertOk()->assertJsonCount(0, 'uploads');

    $this->actingAs($this->patient, 'sanctum')->getJson('/api/v1/prescription-uploads')
        ->assertOk()
        ->assertJsonCount(2, 'uploads')
        ->assertJsonPath('uploads.0.title', 'Newer');
});

it('refuses another patient and doctors', function () {
    uploadPaperPrescription($this);
    $upload = PrescriptionUpload::sole();

    $other = makePatient();
    $this->actingAs($other, 'sanctum')->getJson("/api/v1/prescription-uploads/{$upload->id}")->assertForbidden();
    $this->actingAs($other, 'sanctum')->deleteJson("/api/v1/prescription-uploads/{$upload->id}")->assertForbidden();

    $doctor = makeDoctor();
    $this->actingAs($doctor, 'sanctum')->getJson("/api/v1/prescription-uploads/{$upload->id}")->assertForbidden();
    $this->actingAs($doctor, 'sanctum')->getJson('/api/v1/prescription-uploads')->assertForbidden();

    expect(PrescriptionUpload::count())->toBe(1);
});

it('rejects wrong file types, too many pages and future dates', function (array $data, string $field) {
    $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/prescription-uploads', $data, ['Accept' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors($field);

    expect(PrescriptionUpload::count())->toBe(0);
})->with([
    'no files' => [[], 'files'],
    'exe file' => [fn () => ['files' => [UploadedFile::fake()->create('virus.exe', 10)]], 'files.0'],
    'eleven pages' => [fn () => ['files' => array_map(fn ($i) => fakeJpeg("p{$i}.jpg"), range(1, 11))], 'files'],
    'future date' => [fn () => ['files' => [fakeJpeg('p.jpg')], 'issued_on' => now()->addDays(2)->format('Y-m-d')], 'issued_on'],
]);

it('accepts the Dhaka date of today just after local midnight, while UTC is still on yesterday', function () {
    // 00:21 on 25 Oct in Dhaka is 18:21 on 24 Oct in UTC.
    $this->travelTo(\Carbon\Carbon::parse('2026-10-24 18:21:00', 'UTC'));

    $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/prescription-uploads', [
            'files' => [fakeJpeg('p.jpg')],
            'issued_on' => '2026-10-25',
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('upload.issuedOn', '2026-10-25');
});

it('deletes the rows and the files', function () {
    uploadPaperPrescription($this);
    $upload = PrescriptionUpload::with('pages')->sole();
    $paths = $upload->pages->pluck('file_path')->all();

    $this->actingAs($this->patient, 'sanctum')
        ->deleteJson("/api/v1/prescription-uploads/{$upload->id}")
        ->assertOk();

    expect(PrescriptionUpload::count())->toBe(0);
    foreach ($paths as $path) {
        Storage::disk('private')->assertMissing($path);
    }
});

it('accepts a PDF and marks it as one', function () {
    $this->actingAs($this->patient, 'sanctum')
        ->post('/api/v1/prescription-uploads', [
            'files' => [UploadedFile::fake()->create('rx.pdf', 100, 'application/pdf')],
        ], ['Accept' => 'application/json'])
        ->assertCreated()
        ->assertJsonPath('upload.pages.0.isPdf', true)
        ->assertJsonPath('upload.title', null);
});

it('requires login', function () {
    $this->getJson('/api/v1/prescription-uploads')->assertUnauthorized();
});
