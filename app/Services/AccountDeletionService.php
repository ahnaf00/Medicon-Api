<?php

namespace App\Services;

use App\Models\AiChatSession;
use App\Models\AiTriageLog;
use App\Models\Appointment;
use App\Models\ConsultationSummary;
use App\Models\Conversation;
use App\Models\MedicalRecord;
use App\Models\PatientProfile;
use App\Models\Prescription;
use App\Models\PrescriptionUpload;
use App\Models\User;
use App\Models\Vital;
use App\Notifications\AppNotice;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Account deletion and data export (audit L3).
 *
 * 1. requestDeletion(): soft-deletes the user (hidden everywhere), signs them
 *    out, cancels upcoming visits and tells the other person.
 * 2. Within GRACE_DAYS the person can sign in and restore().
 * 3. purge() (daily, after the grace period) removes personal details and the
 *    person's own data. Clinical records written by doctors (visits,
 *    prescriptions, visit summaries) are kept without the patient's name.
 */
class AccountDeletionService
{
    public const GRACE_DAYS = 30;

    public const DELETED_NAME = 'Deleted user';

    public function __construct(private InAppNotifier $notifier) {}

    public function purgeDate(User $user): ?\Illuminate\Support\Carbon
    {
        return $user->deleted_at?->copy()->addDays(self::GRACE_DAYS);
    }

    public function requestDeletion(User $user): void
    {
        $cancelled = DB::transaction(function () use ($user) {
            $upcoming = Appointment::where('status', 'scheduled')
                ->where(fn ($q) => $q->where('patient_user_id', $user->id)->orWhere('doctor_user_id', $user->id))
                ->lockForUpdate()
                ->get();

            foreach ($upcoming as $visit) {
                $visit->update(['status' => 'cancelled']);
            }

            $user->doctorProfile?->forceFill(['is_online' => false])->save();
            $user->tokens()->delete();
            $user->delete();

            return $upcoming;
        });

        foreach ($cancelled as $visit) {
            $otherId = (int) $visit->patient_user_id === $user->id ? $visit->doctor_user_id : $visit->patient_user_id;
            $this->notifier->send(User::find($otherId), new AppNotice(
                AppNotice::VISIT_CANCELLED,
                'A visit was cancelled',
                "{$user->name} closed their account, so the visit on "
                    .$visit->appointment_datetime->copy()->setTimezone(DoctorSlotService::timezone())->format('j M, g:i A')
                    .' was cancelled.',
                ['appointment_id' => $visit->id],
            ));
        }
    }

    public function restore(User $user): void
    {
        $user->restore();
    }

    /** Removes personal data for good; safe to run twice. */
    public function purge(User $user): void
    {
        if ($user->purged_at) {
            return;
        }

        $files = [];

        foreach (MedicalRecord::where('patient_user_id', $user->id)->with('pages')->get() as $record) {
            $files = [...$files, ...$record->pages->pluck('file_path')->all(), $record->file_path];
        }
        foreach (PrescriptionUpload::where('patient_user_id', $user->id)->with('pages')->get() as $upload) {
            $files = [...$files, ...$upload->pages->pluck('file_path')->all()];
        }

        DB::transaction(function () use ($user) {
            // The person's own data. Lab results, pages and messages go with their parents.
            MedicalRecord::where('patient_user_id', $user->id)->delete();
            PrescriptionUpload::where('patient_user_id', $user->id)->delete();
            Vital::where('user_id', $user->id)->delete();
            AiChatSession::where('user_id', $user->id)->delete();
            AiTriageLog::where('user_id', $user->id)->delete();
            Conversation::where('patient_user_id', $user->id)->delete();
            Conversation::where('type', Conversation::TYPE_DIRECT)->where('doctor_user_id', $user->id)->delete();
            $user->notifications()->delete();
            DB::table('phone_otps')->where('phone', $user->phone)->delete();

            if ($user->hasRole('doctor')) {
                // A doctor's name and registration stay on the prescriptions they issued.
                $user->doctorProfile?->forceFill(['bio' => null, 'is_online' => false])->save();
            } else {
                PatientProfile::where('user_id', $user->id)->delete();
            }

            $user->forceFill([
                'name' => $user->hasRole('doctor') ? $user->name : self::DELETED_NAME,
                'email' => null,
                'phone' => null,
                'password' => null,
                'avatar_url' => null,
                'remember_token' => null,
                'suspension_reason' => null,
                'purged_at' => now(),
            ])->saveQuietly();
        });

        Storage::disk('private')->delete(array_values(array_filter($files)));
    }

    /** Everything the app holds about the person, as plain data (no internal file paths). */
    public function export(User $user): array
    {
        $id = $user->id;
        $strip = fn ($rows) => collect($rows)->map(fn ($r) => collect($r)->except(['file_path'])->all())->all();

        return [
            'exported_at' => now()->toIso8601String(),
            'account' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'phone' => $user->phone,
                'roles' => $user->getRoleNames()->all(),
                'status' => $user->status,
                'created_at' => $user->created_at?->toIso8601String(),
            ],
            'patient_profile' => $user->patientProfile?->toArray(),
            'doctor_profile' => $user->doctorProfile
                ? collect($user->doctorProfile->toArray())->except(['reviewed_by_user_id'])->all()
                : null,
            'appointments' => Appointment::where('patient_user_id', $id)->orWhere('doctor_user_id', $id)
                ->orderBy('appointment_datetime')->get()->toArray(),
            'prescriptions' => Prescription::where('patient_user_id', $id)->orWhere('doctor_user_id', $id)
                ->with(['items', 'tests'])->oldest()->get()->toArray(),
            'visit_summaries' => ConsultationSummary::where('doctor_user_id', $id)
                ->orWhere(fn ($q) => $q->where('patient_user_id', $id)->whereNotNull('published_at'))
                ->oldest()->get()->toArray(),
            'medical_reports' => MedicalRecord::where('patient_user_id', $id)
                ->with('labResults')->withCount('pages')->oldest()->get()
                ->map(fn ($r) => collect($r->toArray())->except(['file_path'])->all())->all(),
            'uploaded_prescriptions' => $strip(PrescriptionUpload::where('patient_user_id', $id)
                ->withCount('pages')->oldest()->get()->toArray()),
            'vitals' => Vital::where('user_id', $id)->oldest()->get()->toArray(),
            'conversations' => Conversation::where('patient_user_id', $id)->orWhere('doctor_user_id', $id)
                ->with('messages')->oldest()->get()->toArray(),
            'ai_chats' => AiChatSession::where('user_id', $id)->with('messages')->oldest()->get()->toArray(),
            'notifications' => $user->notifications()->get(['id', 'data', 'read_at', 'created_at'])->toArray(),
        ];
    }
}
