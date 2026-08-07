<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable()->change();
            $table->string('password')->nullable()->change();
        });

        // Safely alter the enum
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'suspend', 'suspended', 'pending') DEFAULT 'active'");
        DB::statement("UPDATE users SET status = 'suspended' WHERE status = 'suspend'");
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'suspended', 'pending') DEFAULT 'active'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('email')->nullable(false)->change();
            $table->string('password')->nullable(false)->change();
        });

        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'suspend', 'suspended', 'pending') DEFAULT 'active'");
        DB::statement("UPDATE users SET status = 'suspend' WHERE status = 'suspended'");
        DB::statement("ALTER TABLE users MODIFY COLUMN status ENUM('active', 'suspend', 'pending') DEFAULT 'active'");
    }
};
