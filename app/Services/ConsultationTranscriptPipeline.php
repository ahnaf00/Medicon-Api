<?php

namespace App\Services;

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
}
