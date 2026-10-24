<?php

namespace App\Services;

/**
 * Builds Gemini API URLs from config, so every feature uses the same pinned
 * model (`services.gemini.model`, env GEMINI_MODEL). The API key never goes in
 * the URL; callers send it in the x-goog-api-key header.
 */
class GeminiEndpoint
{
    private const BASE = 'https://generativelanguage.googleapis.com/v1beta/models/';

    public static function generate(): string
    {
        return self::BASE.self::model().':generateContent';
    }

    public static function stream(): string
    {
        return self::BASE.self::model().':streamGenerateContent?alt=sse';
    }

    public static function model(): string
    {
        return (string) config('services.gemini.model');
    }
}
