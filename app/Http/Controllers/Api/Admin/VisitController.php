<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\DoctorSlotService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;

/**
 * Admin Visits tab (audit L2b step 2). View only (owner decision 2026-10-25),
 * and records only: who, when, format and status. The patient's reason for
 * the visit, prescriptions, transcripts and summaries are never returned;
 * the detail only says whether a prescription or transcript exists.
 */
class VisitController extends Controller
{
    private const STATUSES = ['scheduled', 'in_progress', 'completed', 'cancelled', 'no_show', 'expired'];

    private const MAX_DAYS = 31;

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            // Clinic-timezone (Asia/Dhaka) calendar days, inclusive. Default: today.
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'status' => ['nullable', 'in:'.implode(',', self::STATUSES)],
        ]);

        $tz = DoctorSlotService::timezone();
        $from = Carbon::parse($validated['from'] ?? now($tz)->toDateString(), $tz)->startOfDay();
        $to = Carbon::parse($validated['to'] ?? $from->toDateString(), $tz)->startOfDay();

        if ($from->diffInDays($to) >= self::MAX_DAYS) {
            return response()->json(['message' => 'Choose a range of '.self::MAX_DAYS.' days or fewer.'], 422);
        }

        $page = Appointment::query()
            ->whereBetween('appointment_datetime', [$from->copy()->utc(), $to->copy()->addDay()->utc()->subSecond()])
            ->when($validated['status'] ?? null, fn ($q, $s) => $q->where('status', $s))
            ->with(['patient', 'doctor.doctorProfile'])
            ->orderBy('appointment_datetime')
            ->orderBy('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Appointment $a) => $this->present($a))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        $visit = Appointment::with(['patient', 'doctor.doctorProfile', 'transcript'])
            ->withExists('prescription')
            ->findOrFail($id);

        return response()->json(['data' => $this->present($visit) + [
            'bookedAt' => $visit->created_at?->toIso8601String(),
            'startedAt' => $visit->started_at?->toIso8601String(),
            'endedAt' => $visit->ended_at?->toIso8601String(),
            'hasPrescription' => (bool) $visit->prescription_exists,
            'transcriptStatus' => $visit->transcript?->status,
        ]]);
    }

    /** @return array<string, mixed> */
    private function present(Appointment $a): array
    {
        return [
            'id' => $a->id,
            'at' => $a->appointment_datetime?->toIso8601String(),
            'durationMinutes' => (int) $a->duration_minutes,
            'format' => $a->format,
            'status' => $a->status,
            'patient' => $this->person($a->patient),
            'doctor' => $this->person($a->doctor) + ['specialty' => $a->doctor?->doctorProfile?->specialty],
        ];
    }

    /** @return array{id: int|null, name: string} */
    private function person(?User $u): array
    {
        return [
            'id' => $u?->id,
            'name' => ! $u || $u->purged_at ? AccountDeletionService::DELETED_NAME : $u->name,
        ];
    }
}
