<?php

namespace App\Services;

use RuntimeException;

/**
 * An analysis failure whose message is safe to show to the patient.
 */
class ReportAnalysisException extends RuntimeException
{
}
