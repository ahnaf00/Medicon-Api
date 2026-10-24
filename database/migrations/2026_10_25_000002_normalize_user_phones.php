<?php

use App\Support\PhoneNumber;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Audit F13: store every phone as +8801XXXXXXXXX. Requests convert numbers the
 * same way, so each number belongs to one account whatever format is typed.
 *
 * Rows are only rewritten when that is safe. A number that isn't a Bangladeshi
 * mobile, or whose +880 form already belongs to another account (the same
 * person registered twice), is left as it is and reported for the owner to
 * resolve: merging or deleting accounts is not done here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $taken = DB::table('users')->whereNotNull('phone')->pluck('id', 'phone')->all();

        DB::table('users')->whereNotNull('phone')->orderBy('id')->get(['id', 'phone'])
            ->each(function (object $user) use (&$taken) {
                $phone = PhoneNumber::normalize($user->phone);

                if ($phone === null) {
                    $this->report("user {$user->id}: '{$user->phone}' is not a Bangladeshi mobile number; left unchanged.");

                    return;
                }
                if ($phone === $user->phone) {
                    return;
                }
                if (isset($taken[$phone])) {
                    $this->report("user {$user->id}: '{$user->phone}' is {$phone}, which user {$taken[$phone]} already has; left unchanged.");

                    return;
                }

                DB::table('users')->where('id', $user->id)->update(['phone' => $phone]);
                unset($taken[$user->phone]);
                $taken[$phone] = $user->id;
            });

        // Pending codes expire in minutes; any in the old format are dropped
        // rather than risk two rows for one number.
        $stale = DB::table('phone_otps')->get(['id', 'phone'])
            ->filter(fn (object $otp) => PhoneNumber::normalize($otp->phone) !== $otp->phone)
            ->pluck('id');
        DB::table('phone_otps')->whereIn('id', $stale)->delete();
    }

    public function down(): void
    {
        // The original formats aren't kept, and the +880 form works with the
        // older code too, so there is nothing to undo.
    }

    private function report(string $message): void
    {
        Log::warning('[phones] '.$message);

        if (app()->runningInConsole()) {
            fwrite(STDOUT, '  [phones] '.$message.PHP_EOL);
        }
    }
};
