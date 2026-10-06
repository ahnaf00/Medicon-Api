<?php

namespace App\Services;

use App\Jobs\SummarizeTranscriptJob;
use App\Jobs\TranscribeConsultationJob;
use App\Models\ConsultationTranscript;
use Illuminate\Support\Facades\DB;

/**
 * Moves a consultation transcript out of the call phase once the visit is over.
 *
 * Called by the agent's `/complete` and by the delayed fallback queued when the
 * doctor ends the call, in either order and any number of times: only the first
 * call that finds the visit completed and the transcript still open acts.
 */
class ConsultationTranscriptPipeline
{
    public function finalize(ConsultationTranscript $transcript): void
    {
        $queued = DB::transaction(function () use ($transcript) {
            $locked = ConsultationTranscript::with('appointment')->lockForUpdate()->find($transcript->getKey());

            if (! $locked || ! $locked->isOpen() || $locked->appointment?->status !== 'completed') {
                return false;
            }

            if ($locked->audioChunks()->exists()) {
                $locked->update(['status' => 'transcribing', 'error' => null, 'skip_reason' => null]);

                return true;
            }

            $locked->update([
                'status' => 'skipped',
                'skip_reason' => $locked->everBothConsented()
                    ? ConsultationTranscript::SKIP_NO_AUDIO
                    : ConsultationTranscript::SKIP_NO_CONSENT,
            ]);

            return false;
        });

        if ($queued) {
            TranscribeConsultationJob::dispatch($transcript->fresh());
        }
    }

    /**
     * Re-run a failed transcript from the step that failed: the summary alone
     * when the transcription had finished, otherwise the transcription. The
     * audio is always still there (failures never delete it).
     *
     * Returns false when the transcript is not in a retryable state.
     */
    public function retry(ConsultationTranscript $transcript): bool
    {
        $job = DB::transaction(function () use ($transcript) {
            $locked = ConsultationTranscript::lockForUpdate()->find($transcript->getKey());

            if (! $locked || $locked->status !== 'failed') {
                return null;
            }

            if ($locked->transcribed_at !== null && $locked->segments()->exists()) {
                $locked->update(['status' => 'summarizing', 'error' => null]);

                return SummarizeTranscriptJob::class;
            }

            if ($locked->audioChunks()->exists()) {
                $locked->update(['status' => 'transcribing', 'error' => null]);

                return TranscribeConsultationJob::class;
            }

            return null;
        });

        if ($job === null) {
            return false;
        }

        $job::dispatch($transcript->fresh());

        return true;
    }
}
