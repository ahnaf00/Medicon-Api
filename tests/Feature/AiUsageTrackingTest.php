<?php

use App\Models\AiUsageEvent;
use App\Services\GeminiEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Audit L2b step 3: every call to the AI service is counted, with no user and no content.

beforeEach(function () {
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();
});

function callGemini(?string $feature): void
{
    $request = Http::withHeaders(['x-goog-api-key' => 'test-key']);
    if ($feature !== null) {
        $request = $request->withAttributes([AiUsageEvent::ATTRIBUTE => $feature]);
    }
    $request->post(GeminiEndpoint::generate(), ['contents' => [['parts' => [['text' => 'PRIVATE prompt']]]]]);
}

it('counts a tagged call with its outcome, and nothing about who or what', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::sequence()->push(['ok' => true])->push('error', 503)]);

    callGemini(AiUsageEvent::REPORT_ANALYSIS);
    callGemini(AiUsageEvent::REPORT_ANALYSIS);

    expect(AiUsageEvent::orderBy('id')->get(['feature', 'succeeded', 'http_status'])->toArray())->toBe([
        ['feature' => 'report_analysis', 'succeeded' => true, 'http_status' => 200],
        ['feature' => 'report_analysis', 'succeeded' => false, 'http_status' => 503],
    ])->and(array_keys(AiUsageEvent::first()->getAttributes()))
        ->toBe(['id', 'feature', 'succeeded', 'http_status', 'duration_ms', 'created_at']);
});

it('never sends the feature tag to the AI service', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['ok' => true])]);

    callGemini(AiUsageEvent::CHAT);

    Http::assertSent(fn ($request) => ! str_contains(json_encode($request->headers()).$request->body(), AiUsageEvent::CHAT));
});

it('counts an untagged AI call as other, and ignores calls to other services', function () {
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(['ok' => true]),
        'api.fda.gov/*' => Http::response(['results' => []]),
    ]);

    callGemini(null);
    Http::get('https://api.fda.gov/drug/label.json');

    expect(AiUsageEvent::pluck('feature')->all())->toBe(['other']);
});

it('counts a call that never reached the AI service as failed', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::failedConnection()]);

    try {
        callGemini(AiUsageEvent::SYMPTOM_CHECK);
    } catch (Throwable) {
        // The feature handles this itself; only the count matters here.
    }

    expect(AiUsageEvent::sole()->only(['feature', 'succeeded', 'http_status']))
        ->toBe(['feature' => 'symptom_check', 'succeeded' => false, 'http_status' => null]);
});

it('counts the AI chat feature end to end', function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response([
        'candidates' => [['content' => ['parts' => [['text' => 'Rest and drink water.']]]]],
    ])]);

    $this->actingAs(makePatient(), 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'I have a mild headache'])
        ->assertCreated();

    expect(AiUsageEvent::sole()->feature)->toBe(AiUsageEvent::CHAT);
});

it('deletes counts older than the retention period', function () {
    $old = AiUsageEvent::forceCreate(['feature' => 'chat', 'succeeded' => true, 'created_at' => now()->subDays(91)]);
    $recent = AiUsageEvent::forceCreate(['feature' => 'chat', 'succeeded' => true, 'created_at' => now()->subDays(89)]);

    Artisan::call('model:prune', ['--model' => [AiUsageEvent::class]]);

    expect(AiUsageEvent::pluck('id')->all())->toBe([$recent->id])
        ->and(AiUsageEvent::find($old->id))->toBeNull();
});
