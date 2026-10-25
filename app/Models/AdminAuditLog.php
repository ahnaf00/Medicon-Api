<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/**
 * One admin action (audit L2b). Append-only: rows are never edited or deleted.
 */
class AdminAuditLog extends Model
{
    public const UPDATED_AT = null;

    public const DOCTOR_APPROVED = 'doctor.approved';

    public const DOCTOR_REJECTED = 'doctor.rejected';

    public const USER_SUSPENDED = 'user.suspended';

    public const USER_REACTIVATED = 'user.reactivated';

    protected $fillable = ['admin_user_id', 'action', 'target_type', 'target_id', 'reason', 'meta'];

    protected function casts(): array
    {
        return ['meta' => 'array', 'created_at' => 'datetime'];
    }

    protected static function booted(): void
    {
        static::updating(fn () => throw new LogicException('Admin audit log rows cannot be changed.'));
        static::deleting(fn () => throw new LogicException('Admin audit log rows cannot be deleted.'));
    }

    /** @param  array<string, mixed>  $meta */
    public static function record(User $admin, string $action, ?User $target = null, ?string $reason = null, array $meta = []): self
    {
        return self::create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'target_type' => $target ? 'user' : null,
            'target_id' => $target?->id,
            'reason' => $reason,
            'meta' => $meta ?: null,
        ]);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id')->withTrashed();
    }

    public function targetUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'target_id')->withTrashed();
    }
}
