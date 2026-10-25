<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
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

    public const HOSPITAL_CREATED = 'hospital.created';

    public const HOSPITAL_UPDATED = 'hospital.updated';

    public const HOSPITAL_DEACTIVATED = 'hospital.deactivated';

    public const HOSPITAL_REACTIVATED = 'hospital.reactivated';

    public const TRANSCRIPT_RETRIED = 'transcript.retried';

    public const JOB_RETRIED = 'job.retried';

    public const JOB_DISCARDED = 'job.discarded';

    /** What a row can point at, stored as `target_type`. */
    public const TARGET_TYPES = [
        User::class => 'user',
        Hospital::class => 'hospital',
        Appointment::class => 'visit',
    ];

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

    /**
     * Rows point at the target by type and id only, so a purged name is never copied here.
     *
     * @param  array<string, mixed>  $meta
     */
    public static function record(User $admin, string $action, ?Model $target = null, ?string $reason = null, array $meta = []): self
    {
        if ($target && ! isset(self::TARGET_TYPES[$target::class])) {
            throw new InvalidArgumentException('The admin audit log cannot point at '.$target::class.'.');
        }

        return self::create([
            'admin_user_id' => $admin->id,
            'action' => $action,
            'target_type' => $target ? self::TARGET_TYPES[$target::class] : null,
            'target_id' => $target?->getKey(),
            'reason' => $reason,
            'meta' => $meta ?: null,
        ]);
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_user_id')->withTrashed();
    }
}
