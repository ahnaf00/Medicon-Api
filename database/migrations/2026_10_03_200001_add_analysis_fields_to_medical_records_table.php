<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->string('title')->nullable()->after('recorded_by_user_id');
            $table->string('laboratory_name')->nullable()->after('title');
            $table->date('report_date')->nullable()->after('laboratory_name');
            $table->enum('analysis_status', ['pending', 'processing', 'completed', 'failed'])
                ->default('pending')
                ->after('notes');
            // Short, user-facing reason shown when analysis is unavailable.
            $table->string('analysis_error')->nullable()->after('analysis_status');
            $table->text('ai_summary')->nullable()->after('analysis_error');
            $table->timestamp('analyzed_at')->nullable()->after('ai_summary');
        });
    }

    public function down(): void
    {
        Schema::table('medical_records', function (Blueprint $table) {
            $table->dropColumn([
                'title',
                'laboratory_name',
                'report_date',
                'analysis_status',
                'analysis_error',
                'ai_summary',
                'analyzed_at',
            ]);
        });
    }
};
