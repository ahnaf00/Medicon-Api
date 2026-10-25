<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin dashboard (audit L2b, step 1):
 * - why and by whom an account was suspended;
 * - an append-only log of every admin action. Rows point at the target by id
 *   only, so a purged account's name is never copied here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->text('suspension_reason')->nullable()->after('status');
            $table->timestamp('suspended_at')->nullable()->after('suspension_reason');
            $table->foreignId('suspended_by_user_id')->nullable()->after('suspended_at')
                ->constrained('users')->nullOnDelete();
        });

        Schema::create('admin_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admin_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('action', 50);
            $table->string('target_type', 30)->nullable();
            $table->unsignedBigInteger('target_id')->nullable();
            $table->text('reason')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['target_type', 'target_id']);
            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admin_audit_logs');

        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('suspended_by_user_id');
            $table->dropColumn(['suspension_reason', 'suspended_at']);
        });
    }
};
