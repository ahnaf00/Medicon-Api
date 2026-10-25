<?php

namespace App\Services;

use App\Models\ConsultationAudioChunk;
use App\Models\ConsultationTranscript;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\RequestException;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Turns a consultation's per-speaker audio chunks into one speaker-labelled,
 * time-ordered transcript with Gemini.
 *
 * Each chunk holds exactly one speaker, so the speaker label comes from the
 * chunk, never from the model. Times are approximate: segment offsets within a
 * chunk are the model's, and chunks are placed by the agent's clock.
 *
 * The audio is only read here. Any failure marks the transcript `failed` with a
 * short reason and keeps every chunk, so it can be retried.
 */
class ConsultationTranscriptionService
{

    /** Base64 inflates by a third; Gemini rejects inline requests above ~20 MB. */
    private const MAX_INLINE_BYTES = 14 * 1024 * 1024;

    private const MAX_SEGMENTS_PER_CHUNK = 2000;

    private const MAX_SEGMENT_CHARS = 5000;

    public const LANGUAGES = ['bn', 'en', 'mixed'];

    public const GENERIC_ERROR = 'The call could not be transcribed. The recording is kept and you can try again.';

    private const SYSTEM_PROMPT = <<<'PROMPT'
You transcribe one participant's microphone from a medical video consultation in Bangladesh.
The audio contains a single speaker (a doctor or a patient). Other voices may be faintly audible
through their speaker; ignore them.

Rules:
- Transcribe verbatim, in the language actually spoken. Write Bangla in Bangla script and English
  as English; keep code-switching exactly as spoken. Never translate.
- Never summarise, correct, complete or add anything. Keep medicine names, doses and numbers exactly as said.
- Split the speech into segments at natural pauses or sentence ends. start_seconds is when the segment
  starts, measured from the beginning of this audio.
- Write [inaudible] for speech you cannot make out. Return no segments for silence or noise.
- language: bn if the speech is Bangla, en if English, mixed if both are used substantially.
PROMPT;

    public function markFailed(ConsultationTranscript $transcript, string $reason): void
    {
        $transcript->forceFill([
            'status' => 'failed',
            'error' => $reason,
        ])->save();
    }

    /**
     * False (and the transcript marked failed) when transcription cannot run at all.
     */
    public function canStart(ConsultationTranscript $transcript): bool
    {
        if (empty(config('services.gemini.api_key'))) {
            $this->markFailed($transcript, 'Automatic transcription is not available right now. The recording is kept.');

            return false;
        }

        if (! $transcript->audioChunks()->exists()) {
            $this->markFailed($transcript, 'There is no recording to transcribe.');

            return false;
        }

        return true;
    }

    /**
     * Transcribes one chunk and stores the result on it (audit G8). On failure the
     * transcript is marked failed with a short reason, the audio and every stored
     * result are kept, and false is returned.
     */
    public function transcribeChunk(ConsultationAudioChunk $chunk): bool
    {
        try {
            $apiKey = config('services.gemini.api_key');
            if (empty($apiKey)) {
                throw new TranscriptionException('Automatic transcription is not available right now. The recording is kept.');
            }

            $chunk->forceFill([
                'result' => $this->requestChunk($chunk, $apiKey),
                'transcribed_at' => now(),
            ])->save();

            return true;
        } catch (TranscriptionException $e) {
            $this->markFailed($chunk->transcript, $e->getMessage());
        } catch (Throwable $e) {
            Log::warning("Transcription failed for audio chunk {$chunk->id}: ".$e->getMessage());
            $this->markFailed($chunk->transcript, self::GENERIC_ERROR);
        }

        return false;
    }

    /**
     * Once every chunk has a result, builds the speaker-labelled, time-ordered
     * transcript from them. Runs under a lock, so of several chunk jobs finishing
     * together exactly one assembles. Returns the new status, or null when it is
     * not time yet (or another job already did it).
     */
    public function assembleIfComplete(ConsultationTranscript $transcript): ?string
    {
        return DB::transaction(function () use ($transcript) {
            $locked = ConsultationTranscript::lockForUpdate()->find($transcript->getKey());

            if ($locked?->status !== 'transcribing') {
                return null;
            }

            /** @var Collection<int, ConsultationAudioChunk> $chunks */
            $chunks = $locked->audioChunks()->get()
                ->sortBy([fn ($a, $b) => $a->absoluteStartMs() <=> $b->absoluteStartMs(), ['id', 'asc']])
                ->values();

            if ($chunks->isEmpty() || $chunks->contains(fn ($chunk) => $chunk->transcribed_at === null)) {
                return null;
            }

            $zeroMs = $chunks->first()->absoluteStartMs();
            $segments = [];
            $languages = [];

            foreach ($chunks as $chunkIndex => $chunk) {
                $result = $chunk->result ?? ['language' => null, 'segments' => []];

                if ($result['segments'] !== []) {
                    $languages[] = $result['language'];
                }

                $offsetMs = $chunk->absoluteStartMs() - $zeroMs;
                foreach ($result['segments'] as $i => $segment) {
                    $segments[] = [
                        'speaker_role' => $chunk->speaker_role,
                        'start_ms' => $offsetMs + $segment['start_ms'],
                        'text' => $segment['text'],
                        // Tie-breakers: earlier chunk, then the model's order.
                        'chunk' => $chunkIndex,
                        'index' => $i,
                    ];
                }
            }

            usort($segments, fn ($a, $b) => [$a['start_ms'], $a['chunk'], $a['index']] <=> [$b['start_ms'], $b['chunk'], $b['index']]);

            $locked->segments()->delete();

            foreach ($segments as $order => $segment) {
                $locked->segments()->create([
                    'speaker_role' => $segment['speaker_role'],
                    'start_ms' => $segment['start_ms'],
                    'text' => $segment['text'],
                    'order' => $order,
                ]);
            }

            // Nothing intelligible was said: there is nothing to summarise.
            $status = $segments === [] ? 'ready' : 'summarizing';

            $locked->forceFill([
                'status' => $status,
                'error' => null,
                'draft_summary' => null,
                'language' => $this->overallLanguage($languages),
                'transcribed_at' => now(),
            ])->save();

            return $status;
        });
    }

    /**
     * @return array{language: ?string, segments: array<int, array{start_ms: int, text: string}>}
     */
    private function requestChunk(ConsultationAudioChunk $chunk, string $apiKey): array
    {
        $audio = Storage::disk('private')->get($chunk->file_path);
        if ($audio === null) {
            throw new TranscriptionException('Part of the recording could not be read.');
        }
        if (strlen($audio) > self::MAX_INLINE_BYTES) {
            throw new TranscriptionException('Part of the recording is too large to transcribe automatically. It is kept.');
        }

        $response = Http::timeout(120)
            ->withHeaders(['x-goog-api-key' => $apiKey])
            // One more attempt for a dropped connection, a rate limit or a 5xx.
            ->retry(2, 2000, fn (Throwable $e) => $e instanceof ConnectionException
                || ($e instanceof RequestException && ($e->response->serverError() || $e->response->status() === 429)), throw: false)
            ->post(GeminiEndpoint::generate(), [
                'system_instruction' => ['parts' => [['text' => self::SYSTEM_PROMPT]]],
                'contents' => [['parts' => [
                    ['inline_data' => ['mime_type' => 'audio/flac', 'data' => base64_encode($audio)]],
                    ['text' => 'Transcribe this audio.'],
                ]]],
                'generationConfig' => [
                    'temperature' => 0,
                    'responseMimeType' => 'application/json',
                    'responseSchema' => self::responseSchema(),
                ],
            ]);

        return $this->parse($response, $chunk);
    }

    /**
     * @return array{language: ?string, segments: array<int, array{start_ms: int, text: string}>}
     */
    private function parse(Response $response, ConsultationAudioChunk $chunk): array
    {
        if (! $response->successful()) {
            Log::warning("Transcription: Gemini returned {$response->status()} for audio chunk {$chunk->id}");
            throw new TranscriptionException(self::GENERIC_ERROR);
        }

        $data = json_decode((string) $response->json('candidates.0.content.parts.0.text', ''), true);
        if (! is_array($data) || ! is_array($data['segments'] ?? null)) {
            Log::warning("Transcription: unreadable Gemini response for audio chunk {$chunk->id}");
            throw new TranscriptionException(self::GENERIC_ERROR);
        }

        $segments = [];
        $previousMs = 0;
        foreach (array_slice($data['segments'], 0, self::MAX_SEGMENTS_PER_CHUNK) as $row) {
            $text = is_array($row) && is_string($row['text'] ?? null) ? trim($row['text']) : '';
            if ($text === '') {
                continue;
            }

            $startMs = is_numeric($row['start_seconds'] ?? null) ? (int) round($row['start_seconds'] * 1000) : $previousMs;
            // Keep inside the chunk, and keep the model's order within it.
            $startMs = max($previousMs, min(max(0, $startMs), $chunk->duration_ms));
            $previousMs = $startMs;

            $segments[] = ['start_ms' => $startMs, 'text' => mb_substr($text, 0, self::MAX_SEGMENT_CHARS)];
        }

        $language = in_array($data['language'] ?? null, self::LANGUAGES, true) ? $data['language'] : null;

        return ['language' => $language, 'segments' => $segments];
    }

    /**
     * @param  array<int, ?string>  $languages
     */
    private function overallLanguage(array $languages): ?string
    {
        $known = array_values(array_unique(array_filter($languages)));

        return match (count($known)) {
            0 => null,
            1 => $known[0],
            default => 'mixed',
        };
    }

    /**
     * @return array<string, mixed>
     */
    private static function responseSchema(): array
    {
        return [
            'type' => 'OBJECT',
            'properties' => [
                'language' => ['type' => 'STRING', 'enum' => self::LANGUAGES],
                'segments' => [
                    'type' => 'ARRAY',
                    'items' => [
                        'type' => 'OBJECT',
                        'properties' => [
                            'start_seconds' => ['type' => 'NUMBER'],
                            'text' => ['type' => 'STRING'],
                        ],
                        'required' => ['start_seconds', 'text'],
                    ],
                ],
            ],
            'required' => ['language', 'segments'],
        ];
    }
}
