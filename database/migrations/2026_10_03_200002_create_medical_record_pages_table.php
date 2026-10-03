<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('medical_record_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('medical_record_id')->constrained('medical_records')->onDelete('cascade');
            $table->string('file_path');
            $table->unsignedInteger('page_order')->default(0);
            $table->timestamps();
        });

        // Existing single-file records become one-page records, so every record has pages[].
        DB::table('medical_records')
            ->whereNotNull('file_path')
            ->orderBy('id')
            ->each(function ($record) {
                DB::table('medical_record_pages')->insert([
                    'medical_record_id' => $record->id,
                    'file_path' => $record->file_path,
                    'page_order' => 0,
                    'created_at' => $record->created_at,
                    'updated_at' => $record->updated_at,
                ]);
            });
    }

    public function down(): void
    {
        Schema::dropIfExists('medical_record_pages');
    }
};
