<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Models\Hospital;
use App\Models\User;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;

/** Read-only list of admin actions, newest first (audit L2b). */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_id' => ['nullable', 'integer'],
            // Older clients send only target_id, which means an account.
            'target_type' => ['nullable', 'in:'.implode(',', AdminAuditLog::TARGET_TYPES)],
        ]);

        $page = AdminAuditLog::with('admin')
            ->when($validated['target_id'] ?? null, fn ($q, $id) => $q
                ->where('target_type', $validated['target_type'] ?? 'user')
                ->where('target_id', $id))
            ->latest('id')
            ->paginate(30);

        $rows = collect($page->items());
        $names = $this->targetNames($rows);

        return response()->json([
            'data' => $rows->map(fn (AdminAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'admin' => $log->admin?->name,
                'target' => $log->target_id ? [
                    'type' => $log->target_type,
                    'id' => $log->target_id,
                    'name' => $names[$log->target_type][$log->target_id] ?? $this->fallbackName($log),
                ] : null,
                'reason' => $log->reason,
                'meta' => $log->meta,
                'at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }

    /**
     * Current names, one query per target type. A purged account shows the
     * deleted-account name, never the name it had.
     *
     * @param  Collection<int, AdminAuditLog>  $rows
     * @return array<string, array<int, string>>
     */
    private function targetNames(Collection $rows): array
    {
        $ids = fn (string $type) => $rows->where('target_type', $type)->pluck('target_id')->unique()->all();

        return [
            'user' => User::withTrashed()->whereKey($ids('user'))->get(['id', 'name', 'purged_at'])
                ->mapWithKeys(fn (User $u) => [$u->id => $u->purged_at ? AccountDeletionService::DELETED_NAME : $u->name])
                ->all(),
            'hospital' => Hospital::whereKey($ids('hospital'))->pluck('name', 'id')->all(),
            'visit' => collect($ids('visit'))->mapWithKeys(fn ($id) => [$id => "Visit #{$id}"])->all(),
        ];
    }

    private function fallbackName(AdminAuditLog $log): string
    {
        return $log->target_type === 'user' ? AccountDeletionService::DELETED_NAME : 'Removed record';
    }
}
