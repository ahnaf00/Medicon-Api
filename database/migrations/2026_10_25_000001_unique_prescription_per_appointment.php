<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Audit G1 / DB-001: one prescription per visit. Prescriptions written outside
 * a visit have no appointment (NULL), and NULLs never collide.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('prescriptions', function (Blueprint $table) {
            $table->unique('appointment_id');
        });
    }

    public function down(): void
    {
        // MySQL lets the foreign key use the unique index and drops its own
        // index, so give the foreign key a plain index back first.
        $needsIndex = ! collect(Schema::getIndexes('prescriptions'))->contains(
            fn (array $index) => $index['columns'] === ['appointment_id'] && ! $index['unique'],
        );
        Schema::table('prescriptions', function (Blueprint $table) use ($needsIndex) {
            if ($needsIndex) {
                $table->index('appointment_id');
            }
            $table->dropUnique(['appointment_id']);
        });
    }
};
