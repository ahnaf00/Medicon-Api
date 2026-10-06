<?php

namespace App\Http\Requests\Api\Transcriber;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Carbon;

/**
 * One closed FLAC chunk from the transcriber agent. The caller is
 * authenticated by VerifyTranscriberSecret; the room, identity and consent are
 * checked in TranscriberController.
 */
class ChunkUploadRequest extends FormRequest
{
    /** The agent rotates files every 5 minutes; allow a little slack. */
    public const MAX_DURATION_MS = 6 * 60 * 1000;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            // A 5-minute 16 kHz mono FLAC is about 4-5 MB.
            'file' => ['required', 'file', 'mimetypes:audio/flac,audio/x-flac', 'max:15360'],
            'identity' => ['required', 'string', 'regex:/^user-\d+$/'],
            'started_at_ms' => ['required', 'integer', 'min:0', 'max:86400000'],
            'duration_ms' => ['required', 'integer', 'min:1', 'max:'.self::MAX_DURATION_MS],
            'session_started_at' => ['required', 'date'],
        ];
    }

    public function userId(): int
    {
        return (int) substr($this->validated('identity'), strlen('user-'));
    }

    public function sessionStartedAtMs(): int
    {
        return Carbon::parse($this->validated('session_started_at'))->getTimestampMs();
    }
}
