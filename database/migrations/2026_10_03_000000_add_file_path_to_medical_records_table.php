<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Medical records move from the public disk (public `file_url`) to the private
     * disk (`file_path`, served via signed URLs). Existing uploads are moved too,
     * otherwise they would stay world-readable.
     */
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('file_path')->nullable()->after('file_url');
        });

        $public = Storage::disk('public');
        $private = Storage::disk('private');

        DB::table('medical_records')
            ->whereNotNull('file_url')
            ->where('file_url', 'like', '%/storage/medical-records/%')
            ->orderBy('id')
            ->each(function ($record) use ($public, $private) {
                $path = 'medical-records/'.Str::after($record->file_url, '/storage/medical-records/');

                if (! $public->exists($path)) {
                    return; // Leave the row untouched if the file is already missing.
                }

                // Copy, verify, then delete: the original must never be lost mid-move.
                $private->writeStream($path, $public->readStream($path));
                if (! $private->exists($path)) {
                    return;
                }
                $public->delete($path);

                DB::table('medical_records')->where('id', $record->id)->update([
                    'file_path' => $path,
                    'file_url' => null,
                ]);
            });
    }

    public function down(): void
    {
        $public = Storage::disk('public');
        $private = Storage::disk('private');

        DB::table('medical_records')
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->each(function ($record) use ($public, $private) {
                if ($private->exists($record->file_path)) {
                    $public->writeStream($record->file_path, $private->readStream($record->file_path));
                    $private->delete($record->file_path);
                }

                DB::table('medical_records')->where('id', $record->id)->update([
                    'file_url' => asset('storage/'.$record->file_path),
                ]);
            });

        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn('file_path');
        });
    }
};
