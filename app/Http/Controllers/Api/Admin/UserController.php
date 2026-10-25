<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Appointment;
use App\Models\User;
use App\Services\AccountDeletionService;
use App\Services\AccountSuspensionService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Admin view of accounts (audit L2b). Records only: who, role, status and
 * dates. Owner decision 2026-10-25: admins never see medical content here.
 */
class UserController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'q' => ['nullable', 'string', 'max:100'],
            'role' => ['nullable', 'in:patient,doctor,admin'],
            'status' => ['nullable', 'in:active,suspended,pending,deleted'],
        ]);
        $status = $validated['status'] ?? null;

        $page = User::query()
            ->when($status === 'deleted',
                fn (Builder $q) => $q->onlyTrashed()->whereNull('purged_at'),
                fn (Builder $q) => $q->when($status, fn ($q) => $q->where('status', $status)))
            ->when($validated['role'] ?? null, fn ($q, $role) => $q->role($role))
            ->when(trim($validated['q'] ?? ''), fn ($q, $term) => $this->search($q, $term))
            ->with('roles')
            ->withMax('tokens', 'last_used_at')
            ->latest('id')
            ->paginate(20);

        return response()->json([
            'data' => collect($page->items())->map(fn (User $u) => $this->present($u))->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ]);
    }

    public function show(AccountDeletionService $deletion, int $id): JsonResponse
    {
        $user = User::withTrashed()->with(['roles', 'doctorProfile'])->withMax('tokens', 'last_used_at')->findOrFail($id);
        $visits = Appointment::where(fn ($q) => $q->where('patient_user_id', $user->id)->orWhere('doctor_user_id', $user->id));
        $suspendedBy = $user->suspended_by_user_id ? User::withTrashed()->find($user->suspended_by_user_id) : null;

        return response()->json(['data' => $this->present($user) + [
            'suspension' => $user->status === 'suspended' ? [
                'reason' => $user->suspension_reason,
                'at' => $user->suspended_at?->toIso8601String(),
                'by' => $suspendedBy?->name,
            ] : null,
            'purgeAt' => $user->trashed() && ! $user->purged_at ? $deletion->purgeDate($user)?->toIso8601String() : null,
            'visits' => [
                'total' => (clone $visits)->count(),
                'upcoming' => (clone $visits)->where('status', 'scheduled')->count(),
                'completed' => (clone $visits)->where('status', 'completed')->count(),
            ],
            'doctor' => $user->doctorProfile ? [
                'specialty' => $user->doctorProfile->specialty,
                'verificationStatus' => $user->doctorProfile->verification_status,
            ] : null,
        ]]);
    }

    public function suspend(Request $request, AccountSuspensionService $suspension, int $id): JsonResponse
    {
        $validated = $request->validate([
            'reason' => ['required', 'string', 'min:5', 'max:500'],
        ]);
        $user = User::findOrFail($id);

        if ($user->is($request->user())) {
            return response()->json(['message' => 'You cannot suspend your own account.'], 422);
        }
        if ($user->hasRole('admin')) {
            return response()->json(['message' => 'Admin accounts cannot be suspended here.'], 403);
        }
        if ($user->status === 'suspended') {
            return response()->json(['message' => 'This account is already suspended.'], 409);
        }

        $suspension->suspend($request->user(), $user, trim($validated['reason']));

        return response()->json(['message' => 'Account suspended.']);
    }

    public function reactivate(Request $request, AccountSuspensionService $suspension, int $id): JsonResponse
    {
        $user = User::findOrFail($id);

        if ($user->status !== 'suspended') {
            return response()->json(['message' => 'This account is not suspended.'], 409);
        }

        $suspension->reactivate($request->user(), $user);

        return response()->json(['message' => 'Account reactivated.']);
    }

    /** Name or email contains the text; a phone matches with or without the leading 0 / +880. */
    private function search(Builder $query, string $term): Builder
    {
        $digits = preg_replace('/\D/', '', $term);
        $local = preg_replace('/^(880|0)/', '', $digits);

        return $query->where(function (Builder $q) use ($term, $local) {
            $q->where('name', 'like', "%{$term}%")->orWhere('email', 'like', "%{$term}%");
            if (strlen($local) >= 3) {
                $q->orWhere('phone', 'like', "%{$local}%");
            }
        });
    }

    /** @return array<string, mixed> */
    private function present(User $u): array
    {
        return [
            'id' => $u->id,
            'name' => $u->purged_at ? AccountDeletionService::DELETED_NAME : $u->name,
            'phone' => $u->phone,
            'email' => $u->email,
            'role' => $u->roles->first()?->name,
            'status' => $u->status,
            'joinedAt' => $u->created_at?->toIso8601String(),
            'lastActiveAt' => $u->tokens_max_last_used_at
                ? \Illuminate\Support\Carbon::parse($u->tokens_max_last_used_at)->toIso8601String()
                : null,
            'deletedAt' => $u->deleted_at?->toIso8601String(),
        ];
    }
}
