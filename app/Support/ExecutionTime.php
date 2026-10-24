<?php

namespace App\Support;

/**
 * PHP stops a web request after max_execution_time (30 s in php.ini), which is
 * shorter than some AI calls are allowed to take (audit E8).
 */
final class ExecutionTime
{
    /**
     * Give the rest of this request at least $seconds. Does nothing where there
     * is no limit (CLI, queue workers), so it never shortens one.
     */
    public static function atLeast(int $seconds): void
    {
        $limit = (int) ini_get('max_execution_time');

        if ($limit > 0 && $limit < $seconds) {
            set_time_limit($seconds);
        }
    }
}
