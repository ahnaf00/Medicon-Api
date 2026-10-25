<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Conversations are either a Q&A question or a direct doctor-patient chat
 * (audit M1). `direct_pair` ("{patient}-{doctor}") keeps one chat per pair;
 * it is null for questions, which may repeat.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->enum('type', ['question', 'direct'])->default('question')->after('id');
            $table->string('direct_pair', 64)->nullable()->unique()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropUnique(['direct_pair']);
            $table->dropColumn(['type', 'direct_pair']);
        });
    }
};
