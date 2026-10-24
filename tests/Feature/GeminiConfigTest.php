<?php

use App\Services\GeminiEndpoint;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

uses(RefreshDatabase::class);

// Audit SEC-007 (key in URL / logs) and CLIN-012 (one pinned, configurable model).

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.gemini.api_key' => 'test-key', 'services.gemini.model' => 'pinned-test-model']);
    Http::preventStrayRequests();
    $this->patient = makePatient();
});

function geminiText(string $text): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
}

it('pins a non-alias model by default', function () {
    $default = require base_path('config/services.php');

    expect($default['gemini']['model'])->not->toContain('latest');
});

it('builds every Gemini URL from the configured model', function () {
    expect(GeminiEndpoint::generate())->toBe('https://generativelanguage.googleapis.com/v1beta/models/pinned-test-model:generateContent')
        ->and(GeminiEndpoint::stream())->toBe('https://generativelanguage.googleapis.com/v1beta/models/pinned-test-model:streamGenerateContent?alt=sse');
});

it('sends the AI chat key in a header, never in the URL', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiText('Hello'))]);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'hi'])
        ->assertCreated();

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'models/pinned-test-model:generateContent')
        && ! str_contains($r->url(), 'key=')
        && $r->hasHeader('x-goog-api-key', 'test-key'));
});

it('logs only the status when the AI chat provider fails', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('body that echoes test-key', 500)]);
    Log::spy();

    $this->actingAs($this->patient, 'sanctum')->postJson('/api/v1/ai/chat', ['message' => 'hi']);

    Log::shouldHaveReceived('error')->once()->withArgs(fn (string $message) => $message === 'AI chat: Gemini returned HTTP 500');
});

it('uses the configured model for symptom search too', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(geminiText(json_encode(['specialty' => 'Dermatology', 'urgency' => 'low'])))]);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/symptom-search', ['query' => 'itchy rash on my arms'])
        ->assertOk();

    Http::assertSent(fn (HttpRequest $r) => str_contains($r->url(), 'models/pinned-test-model:generateContent'));
});
