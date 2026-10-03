<?php

use App\Models\MedicalRecord;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

function recordFor(User $user, array $attributes = []): MedicalRecord
{
    return MedicalRecord::factory()->create(['patient_user_id' => $user->id] + $attributes);
}

it('starts new records as pending analysis', function () {
    $record = recordFor(User::factory()->create());

    expect($record->fresh()->analysis_status)->toBe('pending');
});

it('returns pages and lab results in order', function () {
    $record = recordFor(User::factory()->create());
    $record->pages()->create(['file_path' => 'medical-records/b.jpg', 'page_order' => 1]);
    $record->pages()->create(['file_path' => 'medical-records/a.jpg', 'page_order' => 0]);
    $record->labResults()->create(['panel' => 'CBC', 'name' => 'Platelets', 'value' => '250', 'order' => 1]);
    $record->labResults()->create(['panel' => 'CBC', 'name' => 'Haemoglobin', 'value' => '13.1', 'order' => 0]);

    expect($record->pages()->pluck('file_path')->all())->toBe(['medical-records/a.jpg', 'medical-records/b.jpg'])
        ->and($record->labResults()->pluck('name')->all())->toBe(['Haemoglobin', 'Platelets']);
});

it('backfills a page row for existing single-file records', function () {
    $user = User::factory()->create();
    $withFile = recordFor($user, ['file_path' => 'medical-records/old.pdf']);
    $withoutFile = recordFor($user, ['file_path' => null]);

    Schema::drop('medical_record_pages');
    (require database_path('migrations/2026_10_03_200002_create_medical_record_pages_table.php'))->up();

    expect(DB::table('medical_record_pages')->where('medical_record_id', $withFile->id)->pluck('file_path')->all())
        ->toBe(['medical-records/old.pdf'])
        ->and(DB::table('medical_record_pages')->where('medical_record_id', $withoutFile->id)->count())->toBe(0);
});
