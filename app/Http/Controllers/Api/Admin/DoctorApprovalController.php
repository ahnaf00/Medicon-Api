<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\DoctorProfile;
use App\Models\User;
use App\Notifications\AppNotice;
use App\Services\InAppNotifier;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin review of doctor registrations (audit L2). Routes sit behind
 * `role:admin`. The app cannot check a BMDC number itself; the admin checks
 * it on the BMDC register before approving.
 */
class DoctorApprovalController extends Controller
{
    private const STATUSES = ['pending', 'verified', 'rejected'];

    /** Numbers for the admin home screen. */
    public function summary(): JsonResponse
    {
        $counts = DoctorProfile::select('verification_status', DB::raw('count(*) as total'))
            ->groupBy('verification_status')
            ->pluck('total', 'verification_status');

        return response()->json([
            'doctors' => [
                'pending' => (int) ($counts['pending'] ?? 0),
                'verified' => (int) ($counts['verified'] ?? 0),
                'rejected' => (int) ($counts['rejected'] ?? 0),
            ],
        ]);
    }

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // `all` is the admin directory (audit L2b step 2); the others are the review queues.
            'status' => ['nullable', 'in:all,'.implode(',', self::STATUSES)],
            'q' => ['nullable', 'string', 'max:100'],
            'online' => ['nullable', 'boolean'],
        ]);
        $status = $validated['status'] ?? 'pending';
        $term = trim($validated['q'] ?? '');

        $page = User::role('doctor')
            ->whereHas('doctorProfile', fn ($q) => $q
                ->when($status !== 'all', fn ($q) => $q->where('verification_status', $status))
                ->when($request->boolean('online'), fn ($q) => $q->where('is_online', true)))
            ->when($term !== '', fn ($q) => $q->where(fn ($q) => $q
                ->where('name', 'like', "%{$term}%")
                ->orWhereHas('doctorProfile', fn ($p) => $p
                    ->where('specialty', 'like', "%{$term}%")
                    ->orWhere('bmdc_registration_no', 'like', "%{$term}%"))))
            ->with('doctorProfile.reviewer')
            ->withCount([
                'appointmentsAsDoctor as visits_completed' => fn ($q) => $q->where('status', 'completed'),
                'appointmentsAsDoctor as visits_upcoming' => fn ($q) => $q->where('status', 'scheduled'),
            ])
            // Directory A-Z; oldest waiting first for the queue; most recently reviewed first otherwise.
            ->when($status === 'all', fn ($q) => $q->orderBy('name'),
                fn ($q) => $status === 'pending' ? $q->oldest() : $q->latest('updated_at'))
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->present($u))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present($this->findDoctor($id))]);
    }

    /** Pending or previously rejected doctors can be approved. */
    public function approve(Request $request, InAppNotifier $notifier, int $id): JsonResponse
    {
        $doctor = $this->findDoctor($id);
        $profile = $doctor->doctorProfile;

        if ($profile->verification_status === 'verified') {
            return response()->json(['message' => 'This doctor is already approved.'], 409);
        }

        DB::transaction(function () use ($request, $doctor, $profile) {
            $profile->forceFill([
                'verification_status' => 'verified',
                'rejection_reason' => null,
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            if ($doctor->status === 'pending') {
                $doctor->forceFill(['status' => 'active'])->save();
            }

            AdminAuditLog::record($request->user(), AdminAuditLog::DOCTOR_APPROVED, $doctor);
        });

        $notifier->send($doctor, new AppNotice(
            AppNotice::ACCOUNT_REVIEWED,
            'Your doctor account is approved',
            'You can now go online, receive appointments and start consultations.',
            ['status' => 'verified'],
        ));

        return response()->json(['message' => 'Doctor approved.', 'data' => $this->present($doctor->fresh('doctorProfile.reviewer'))]);
    }

    /** Only a pending registration can be rejected; an approved doctor is not un-approved here. */
    public function reject(Request $request, InAppNotifier $notifier, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $doctor = $this->findDoctor($id);
        $profile = $doctor->doctorProfile;

        if ($profile->verification_status !== 'pending') {
            return response()->json(['message' => 'Only a pending registration can be rejected.'], 409);
        }

        DB::transaction(function () use ($request, $doctor, $profile, $validated) {
            $profile->forceFill([
                'verification_status' => 'rejected',
                'rejection_reason' => trim($validated['reason']),
                'reviewed_by_user_id' => $request->user()->id,
                'reviewed_at' => now(),
            ])->save();

            AdminAuditLog::record($request->user(), AdminAuditLog::DOCTOR_REJECTED, $doctor, trim($validated['reason']));
        });

        $notifier->send($doctor, new AppNotice(
            AppNotice::ACCOUNT_REVIEWED,
            'Your doctor account was not approved',
            'Reason: '.trim($validated['reason']),
            ['status' => 'rejected'],
        ));

        return response()->json(['message' => 'Doctor rejected.', 'data' => $this->present($doctor->fresh('doctorProfile.reviewer'))]);
    }

    private function findDoctor(int $id): User
    {
        return User::role('doctor')->whereHas('doctorProfile')->with('doctorProfile.reviewer')
            ->withCount([
                'appointmentsAsDoctor as visits_completed' => fn ($q) => $q->where('status', 'completed'),
                'appointmentsAsDoctor as visits_upcoming' => fn ($q) => $q->where('status', 'scheduled'),
            ])
            ->findOrFail($id);
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        $p = $u->doctorProfile;

        return [
            'id' => $u->id,
            'name' => $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'registeredAt' => $u->created_at?->toIso8601String(),
            'specialty' => $p->specialty,
            'qualification' => $p->qualification,
            'bmdcRegistrationNo' => $p->bmdc_registration_no,
            'hospitalName' => $p->hospital_name,
            'experienceYears' => (int) $p->experience_years,
            'verificationStatus' => $p->verification_status,
            'rejectionReason' => $p->rejection_reason,
            'reviewedAt' => $p->reviewed_at?->toIso8601String(),
            'reviewedBy' => $p->reviewer?->name,
            'isOnline' => (bool) $p->is_online,
            'accountStatus' => $u->status,
            'visits' => [
                'completed' => (int) ($u->visits_completed ?? 0),
                'upcoming' => (int) ($u->visits_upcoming ?? 0),
            ],
        ];
    }
}
