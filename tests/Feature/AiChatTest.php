<?php

use App\Models\AiChatMessage;
use App\Models\AiChatSession;
use Database\Seeders\RolesAndPermissionsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request as HttpRequest;
use Illuminate\Support\Facades\Http;

uses(RefreshDatabase::class);

// Audit C8 / CLIN-003: the general AI chat keeps context and never saves a failure as an answer.

beforeEach(function () {
    $this->seed(RolesAndPermissionsSeeder::class);
    config(['services.gemini.api_key' => 'test-key']);
    Http::preventStrayRequests();
    $this->patient = makePatient();
});

function aiChatReply(string $text): array
{
    return ['candidates' => [['content' => ['parts' => [['text' => $text]]]]]];
}

it('starts a session and stores both turns', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(aiChatReply('Drink water and rest.'))]);

    $response = $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'I have a mild headache'])
        ->assertCreated()
        ->assertJsonPath('message.content', 'Drink water and rest.');

    $session = AiChatSession::findOrFail($response->json('sessionId'));
    expect($session->messages()->pluck('role')->all())->toBe(['user', 'assistant']);
});

it('sends the earlier turns so follow-up questions have context', function () {
    $session = AiChatSession::create(['user_id' => $this->patient->id, 'title' => 'Headache']);
    $session->messages()->create(['role' => 'user', 'content' => 'I have a mild headache']);
    $session->messages()->create(['role' => 'assistant', 'content' => 'Drink water and rest.']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(aiChatReply('Paracetamol may help.'))]);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'Can I take something for it?', 'session_id' => $session->id])
        ->assertCreated();

    Http::assertSent(function (HttpRequest $r) {
        $contents = $r->data()['contents'];

        return count($contents) === 3
            && $contents[0]['role'] === 'user' && $contents[0]['parts'][0]['text'] === 'I have a mild headache'
            && $contents[1]['role'] === 'model' && $contents[1]['parts'][0]['text'] === 'Drink water and rest.'
            && $contents[2]['role'] === 'user' && $contents[2]['parts'][0]['text'] === 'Can I take something for it?';
    });
    expect($session->messages()->count())->toBe(4);
});

it('returns 503 and saves nothing when the AI fails', function () {
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response('error', 500)]);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'hi'])
        ->assertStatus(503)
        ->assertJsonPath('message', 'The AI assistant is unavailable right now. Please try again in a moment.');

    expect(AiChatSession::count())->toBe(0)
        ->and(AiChatMessage::count())->toBe(0);
});

it('treats an empty reply as a failure', function () {
    $session = AiChatSession::create(['user_id' => $this->patient->id, 'title' => 'Hi']);
    Http::fake(['generativelanguage.googleapis.com/*' => Http::response(['candidates' => []])]);

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'hi', 'session_id' => $session->id])
        ->assertStatus(503);

    expect($session->messages()->count())->toBe(0);
});

it('does not continue another user\'s session', function () {
    $other = makePatient();
    $session = AiChatSession::create(['user_id' => $other->id, 'title' => 'Theirs']);
    Http::fake();

    $this->actingAs($this->patient, 'sanctum')
        ->postJson('/api/v1/ai/chat', ['message' => 'hi', 'session_id' => $session->id])
        ->assertNotFound();

    Http::assertNothingSent();
});

// Audit CLIN-004: the same fixed emergency screen as symptom search, model or not.
describe('red flags', function () {
    it('flags an emergency alongside the AI reply', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(aiChatReply('Please get help.'))]);

        $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['message' => 'my father took an overdose'])
            ->assertCreated()
            ->assertJsonPath('redFlag.code', 'poisoning');
    });

    it('still flags the emergency when the AI is down', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response('error', 500)]);

        $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['message' => 'I think I am having a heart attack'])
            ->assertStatus(503)
            ->assertJsonPath('redFlag.code', 'heart_attack');
    });

    it('sends no flag for an ordinary question', function () {
        Http::fake(['generativelanguage.googleapis.com/*' => Http::response(aiChatReply('Rest.'))]);

        $this->actingAs($this->patient, 'sanctum')
            ->postJson('/api/v1/ai/chat', ['message' => 'I have a mild headache'])
            ->assertCreated()
            ->assertJsonPath('redFlag', null);
    });
});
