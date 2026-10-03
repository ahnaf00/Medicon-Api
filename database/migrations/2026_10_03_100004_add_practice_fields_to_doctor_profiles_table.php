<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->string('bmdc_registration_no')->nullable()->after('qualification');
            $table->string('hospital_name')->nullable()->after('bmdc_registration_no');
        });
    }

    public function down(): void
    {
        Schema::table('doctor_profiles', function (Blueprint $table) {
            $table->dropColumn(['bmdc_registration_no', 'hospital_name']);
        });
    }
};
