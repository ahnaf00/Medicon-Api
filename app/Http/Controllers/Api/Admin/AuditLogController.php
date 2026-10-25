<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\AdminAuditLog;
use App\Services\AccountDeletionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/** Read-only list of admin actions, newest first (audit L2b). */
class AuditLogController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'target_id' => ['nullable', 'integer'],
        ]);

        $page = AdminAuditLog::with(['admin', 'targetUser'])
            ->when($validated['target_id'] ?? null, fn ($q, $id) => $q->where('target_type', 'user')->where('target_id', $id))
            ->latest('id')
            ->paginate(30);

        return response()->json([
            'data' => collect($page->items())->map(fn (AdminAuditLog $log) => [
                'id' => $log->id,
                'action' => $log->action,
                'admin' => $log->admin?->name,
                'target' => $log->target_id ? [
                    'id' => $log->target_id,
                    'name' => ! $log->targetUser || $log->targetUser->purged_at
                        ? AccountDeletionService::DELETED_NAME
                        : $log->targetUser->name,
                ] : null,
                'reason' => $log->reason,
                'meta' => $log->meta,
                'at' => $log->created_at?->toIso8601String(),
            ])->values(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage()],
        ]);
    }
}
