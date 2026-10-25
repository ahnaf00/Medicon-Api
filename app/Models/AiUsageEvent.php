<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Prunable;

/**
 * One call to the AI provider (audit L2b step 3). No user and no content:
 * only the feature, the outcome and the time taken. Kept for RETENTION_DAYS.
 *
 * Calls are tagged at the call site with
 * `Http::withAttributes([AiUsageEvent::ATTRIBUTE => AiUsageEvent::CHAT])`;
 * the attribute stays inside the app and is never sent to the provider.
 */
class AiUsageEvent extends Model
{
    use Prunable;

    public const UPDATED_AT = null;

    public const RETENTION_DAYS = 90;

    /** Request attribute that names the feature making the call. */
    public const ATTRIBUTE = 'ai_feature';

    public const CHAT = 'chat';

    public const SYMPTOM_CHECK = 'symptom_check';

    public const REPORT_ANALYSIS = 'report_analysis';

    public const MEDICINE_EXPLAINER = 'medicine_explainer';

    public const CALL_TRANSCRIPTION = 'call_transcription';

    public const CALL_SUMMARY = 'call_summary';

    public const CONSULTATION_CHAT = 'consultation_chat';

    /** An AI call no feature tagged; counted so nothing goes missing. */
    public const OTHER = 'other';

    public const FEATURES = [
        self::CHAT,
        self::SYMPTOM_CHECK,
        self::REPORT_ANALYSIS,
        self::MEDICINE_EXPLAINER,
        self::CALL_TRANSCRIPTION,
        self::CALL_SUMMARY,
        self::CONSULTATION_CHAT,
        self::OTHER,
    ];

    protected $fillable = ['feature', 'succeeded', 'http_status', 'duration_ms'];

    protected function casts(): array
    {
        return [
            'succeeded' => 'boolean',
            'http_status' => 'integer',
            'duration_ms' => 'integer',
            'created_at' => 'datetime',
        ];
    }

    public function prunable(): Builder
    {
        return static::where('created_at', '<', now()->subDays(self::RETENTION_DAYS));
    }
}
