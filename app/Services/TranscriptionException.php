<?php

namespace App\Services;

use RuntimeException;

/**
 * A transcription failure whose message is safe to show to the doctor.
 */
class TranscriptionException extends RuntimeException
{
}
