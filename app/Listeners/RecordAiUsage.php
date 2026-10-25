<?php

namespace App\Listeners;

use App\Models\AiUsageEvent;
use App\Services\GeminiEndpoint;
use Illuminate\Http\Client\Events\ConnectionFailed;
use Illuminate\Http\Client\Events\ResponseReceived;
use Illuminate\Http\Client\Request;

/**
 * Counts every call to the AI provider for System health (audit L2b step 3).
 * Listens to the HTTP client, so no feature can call Gemini without being
 * counted. Records no user and no content. A failure to record is reported
 * but never breaks the feature that made the call.
 */
class RecordAiUsage
{
    public function handleResponse(ResponseReceived $event): void
    {
        $stats = $event->response->handlerStats();

        $this->record(
            $event->request,
            $event->response->successful(),
            $event->response->status(),
            isset($stats['total_time']) ? (int) round($stats['total_time'] * 1000) : null,
        );
    }

    public function handleConnectionFailure(ConnectionFailed $event): void
    {
        $this->record($event->request, false, null, null);
    }

    private function record(Request $request, bool $succeeded, ?int $status, ?int $durationMs): void
    {
        if (! GeminiEndpoint::isGemini($request->url())) {
            return;
        }

        $feature = $request->attributes()[AiUsageEvent::ATTRIBUTE] ?? null;

        rescue(fn () => AiUsageEvent::create([
            'feature' => in_array($feature, AiUsageEvent::FEATURES, true) ? $feature : AiUsageEvent::OTHER,
            'succeeded' => $succeeded,
            'http_status' => $status,
            'duration_ms' => $durationMs,
        ]));
    }
}
