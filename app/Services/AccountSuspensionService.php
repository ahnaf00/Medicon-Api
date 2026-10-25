<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\Appointment;
use App\Models\User;
use App\Notifications\AppNotice;
use Illuminate\Support\Facades\DB;

/**
 * Admin suspension of an account (audit L2b). A suspended person is signed out
 * everywhere and blocked by EnsureAccountActive; their upcoming visits are
 * cancelled and the other person is told the visit is off (not why).
 */
class AccountSuspensionService
{
    public function __construct(private InAppNotifier $notifier) {}

    public function suspend(User $admin, User $user, string $reason): void
    {
        $cancelled = DB::transaction(function () use ($admin, $user, $reason) {
            $upcoming = Appointment::where('status', 'scheduled')
                ->where(fn ($q) => $q->where('patient_user_id', $user->id)->orWhere('doctor_user_id', $user->id))
                ->lockForUpdate()
                ->get();

            foreach ($upcoming as $visit) {
                $visit->update(['status' => 'cancelled']);
            }

            $user->forceFill([
                'status' => 'suspended',
                'suspension_reason' => $reason,
                'suspended_at' => now(),
                'suspended_by_user_id' => $admin->id,
            ])->save();
            $user->doctorProfile?->forceFill(['is_online' => false])->save();
            $user->tokens()->delete();

            AdminAuditLog::record($admin, AdminAuditLog::USER_SUSPENDED, $user, $reason, [
                'cancelled_visits' => $upcoming->count(),
            ]);

            return $upcoming;
        });

        foreach ($cancelled as $visit) {
            $otherId = (int) $visit->patient_user_id === $user->id ? $visit->doctor_user_id : $visit->patient_user_id;
            $this->notifier->send(User::find($otherId), new AppNotice(
                AppNotice::VISIT_CANCELLED,
                'A visit was cancelled',
                "{$user->name} is no longer available, so the visit on "
                    .$visit->appointment_datetime->copy()->setTimezone(DoctorSlotService::timezone())->format('j M, g:i A')
                    .' was cancelled.',
                ['appointment_id' => $visit->id],
            ));
        }
    }

    public function reactivate(User $admin, User $user): void
    {
        DB::transaction(function () use ($admin, $user) {
            // A doctor who was never approved goes back to waiting for review.
            $unapprovedDoctor = $user->hasRole('doctor')
                && $user->doctorProfile?->verification_status !== 'verified';

            $user->forceFill([
                'status' => $unapprovedDoctor ? 'pending' : 'active',
                'suspension_reason' => null,
                'suspended_at' => null,
                'suspended_by_user_id' => null,
            ])->save();

            AdminAuditLog::record($admin, AdminAuditLog::USER_REACTIVATED, $user);
        });

        $this->notifier->send($user, new AppNotice(
            AppNotice::ACCOUNT_REVIEWED,
            'Your account is active again',
            'You can use MediCon as before.',
            ['status' => 'reactivated'],
        ));
    }
}
