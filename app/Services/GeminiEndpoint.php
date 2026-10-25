<?php

namespace App\Services;

/**
 * Builds Gemini API URLs from config, so every feature uses the same pinned
 * model (`services.gemini.model`, env GEMINI_MODEL). The API key never goes in
 * the URL; callers send it in the x-goog-api-key header.
 */
class GeminiEndpoint
{
    public const HOST = 'generativelanguage.googleapis.com';

    private const BASE = 'https://'.self::HOST.'/v1beta/models/';

    public static function generate(): string
    {
        return self::BASE.self::model().':generateContent';
    }

    public static function stream(): string
    {
        return self::BASE.self::model().':streamGenerateContent?alt=sse';
    }

    /** Whether a URL is a call to the Gemini API (used to count AI usage). */
    public static function isGemini(string $url): bool
    {
        return parse_url($url, PHP_URL_HOST) === self::HOST;
    }

    public static function model(): string
    {
        return (string) config('services.gemini.model');
    }
}
