<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Admin\HospitalRequest;
use App\Models\AdminAuditLog;
use App\Models\Hospital;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

/**
 * Admin hospitals (audit L2b step 3). Hospitals are never deleted: hiding one
 * removes it from the patient list and can be undone (owner decision
 * 2026-10-25). Every change is written to the admin audit log.
 */
class HospitalController extends Controller
{
    private const EDITABLE = ['name', 'address', 'emergency_phone', 'is_24_7', 'latitude', 'longitude'];

    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'status' => ['nullable', 'in:active,hidden,all'],
        ]);
        $status = $validated['status'] ?? 'all';
        $term = trim($validated['q'] ?? '');

        $page = Hospital::query()
            ->when($status !== 'all', fn ($q) => $q->where('is_active', $status === 'active'))
            ->when($term !== '', fn ($q) => $q->matching($term))
            ->orderBy('name')
            ->orderBy('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (Hospital $h) => $this->present($h))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(int $id): JsonResponse
    {
        return response()->json(['data' => $this->present(Hospital::findOrFail($id))]);
    }

    public function store(HospitalRequest $request): JsonResponse
    {
        $hospital = DB::transaction(function () use ($request) {
            $hospital = Hospital::create($request->safe()->only(self::EDITABLE));
            AdminAuditLog::record($request->user(), AdminAuditLog::HOSPITAL_CREATED, $hospital);

            return $hospital;
        });

        return response()->json(['message' => 'Hospital added.', 'data' => $this->present($hospital->fresh())], 201);
    }

    public function update(HospitalRequest $request, int $id): JsonResponse
    {
        $hospital = Hospital::findOrFail($id);
        $hospital->fill($request->safe()->only(self::EDITABLE));

        if (! $hospital->isDirty()) {
            return response()->json(['message' => 'Nothing changed.', 'data' => $this->present($hospital)]);
        }

        // Old and new values of what changed, so the log can say exactly what an admin did.
        $changes = collect($hospital->getDirty())
            ->mapWithKeys(fn ($new, $field) => [$field => ['from' => $hospital->getOriginal($field), 'to' => $hospital->$field]])
            ->all();

        DB::transaction(function () use ($request, $hospital, $changes) {
            $hospital->save();
            AdminAuditLog::record($request->user(), AdminAuditLog::HOSPITAL_UPDATED, $hospital, null, ['changes' => $changes]);
        });

        return response()->json(['message' => 'Hospital updated.', 'data' => $this->present($hospital->fresh())]);
    }

    /** Hide from patients. Needs a reason, which only admins see. */
    public function deactivate(Request $request, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $hospital = Hospital::findOrFail($id);

        if (! $hospital->is_active) {
            return response()->json(['message' => 'This hospital is already hidden.'], 409);
        }

        DB::transaction(function () use ($request, $hospital, $validated) {
            $hospital->forceFill(['is_active' => false, 'deactivated_at' => now()])->save();
            AdminAuditLog::record($request->user(), AdminAuditLog::HOSPITAL_DEACTIVATED, $hospital, trim($validated['reason']));
        });

        return response()->json(['message' => 'Hospital hidden from patients.', 'data' => $this->present($hospital->fresh())]);
    }

    public function reactivate(Request $request, int $id): JsonResponse
    {
        $hospital = Hospital::findOrFail($id);

        if ($hospital->is_active) {
            return response()->json(['message' => 'This hospital is already shown to patients.'], 409);
        }

        DB::transaction(function () use ($request, $hospital) {
            $hospital->forceFill(['is_active' => true, 'deactivated_at' => null])->save();
            AdminAuditLog::record($request->user(), AdminAuditLog::HOSPITAL_REACTIVATED, $hospital);
        });

        return response()->json(['message' => 'Hospital shown to patients again.', 'data' => $this->present($hospital->fresh())]);
    }

    /** @return array<string, mixed> */
    private function present(Hospital $h): array
    {
        return [
            'id' => $h->id,
            'name' => $h->name,
            'address' => $h->address,
            'latitude' => $h->latitude !== null ? (float) $h->latitude : null,
            'longitude' => $h->longitude !== null ? (float) $h->longitude : null,
            'emergencyPhone' => $h->emergency_phone,
            'is247' => (bool) $h->is_24_7,
            'isActive' => (bool) $h->is_active,
            'deactivatedAt' => $h->deactivated_at?->toIso8601String(),
            'createdAt' => $h->created_at?->toIso8601String(),
            'updatedAt' => $h->updated_at?->toIso8601String(),
        ];
    }
}
