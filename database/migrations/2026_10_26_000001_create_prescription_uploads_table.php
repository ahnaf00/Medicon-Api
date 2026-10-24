<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Task 1.7 (audit D1): paper prescriptions a patient photographs or uploads.
// A sibling table, not rows in `prescriptions`, which require an in-app doctor.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('prescription_uploads', function (Blueprint $table) {
            $table->id();
            $table->foreignId('patient_user_id')->constrained('users')->onDelete('cascade');
            $table->string('title')->nullable();
            $table->string('doctor_name')->nullable();
            $table->date('issued_on')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->index(['patient_user_id', 'created_at']);
        });

        Schema::create('prescription_upload_pages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('prescription_upload_id')->constrained('prescription_uploads')->onDelete('cascade');
            $table->string('file_path');
            $table->string('mime_type', 100);
            $table->unsignedInteger('page_order')->default(0);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('prescription_upload_pages');
        Schema::dropIfExists('prescription_uploads');
    }
};
