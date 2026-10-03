<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The prescription writer used to emit `afternoon` while every reader expects
     * `noon`. Rewrite stored schedules so existing prescriptions show up again.
     */
    public function up(): void
    {
        $this->renameKey('afternoon', 'noon');
    }

    public function down(): void
    {
        $this->renameKey('noon', 'afternoon');
    }

    private function renameKey(string $from, string $to): void
    {
        DB::table('prescription_items')
            ->whereNotNull('dosage_schedule')
            ->where('dosage_schedule', 'like', "%\"{$from}\"%")
            ->orderBy('id')
            ->each(function ($item) use ($from, $to) {
                $schedule = json_decode($item->dosage_schedule, true);
                if (! is_array($schedule) || ! array_key_exists($from, $schedule)) {
                    return;
                }

                $schedule[$to] ??= $schedule[$from];
                unset($schedule[$from]);

                DB::table('prescription_items')
                    ->where('id', $item->id)
                    ->update(['dosage_schedule' => json_encode($schedule)]);
            });
    }
};
