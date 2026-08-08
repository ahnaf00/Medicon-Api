<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiChatMessageResource;
use App\Http\Resources\AiChatSessionResource;
use App\Models\AiChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class AiChatController extends Controller
{
    public function chat(Request $request): JsonResponse
    {
        $request->validate(['message' => ['required', 'string', 'max:2000'], 'session_id' => ['nullable', 'exists:ai_chat_sessions,id']]);

        $user = $request->user();

        // Get or create session
        $session = $request->session_id
            ? AiChatSession::where('id', $request->session_id)->where('user_id', $user->id)->firstOrFail()
            : AiChatSession::create(['user_id' => $user->id, 'title' => substr($request->message, 0, 60)]);

        // Store user message
        $session->messages()->create(['role' => 'user', 'content' => $request->message]);

        // Call Gemini AI engine
        $reply = $this->generateReply($request->message);

        $aiMessage = $session->messages()->create(['role' => 'assistant', 'content' => $reply]);

        return response()->json([
            'sessionId' => $session->id,
            'message' => new AiChatMessageResource($aiMessage),
        ], 201);
    }

    public function sessions(Request $request): AnonymousResourceCollection
    {
        $sessions = AiChatSession::where('user_id', $request->user()->id)
            ->with('latestMessage')
            ->latest()
            ->paginate(20);

        return AiChatSessionResource::collection($sessions);
    }

    public function messages(Request $request, $id): AnonymousResourceCollection
    {
        $session = AiChatSession::where('id', $id)
            ->where('user_id', $request->user()->id)
            ->firstOrFail();

        return AiChatMessageResource::collection($session->messages()->oldest()->paginate(50));
    }

    private function generateReply(string $userMessage): string
    {
        try {
            $apiKey = config('services.gemini.api_key');

            $response = Http::timeout(15)->post("https://generativelanguage.googleapis.com/v1beta/models/gemini-flash-latest:generateContent?key={$apiKey}", [
                'system_instruction' => [
                    'parts' => [
                        ['text' => 'You are MediCon AI, a friendly and helpful health assistant for the MediCon patient app. You help patients understand symptoms, explain medical terms in simple language, and provide general health guidance. Always remind users that your advice is not a substitute for professional medical consultation. Keep responses concise (under 200 words). If the situation sounds urgent or life-threatening, strongly advise them to call emergency services or use the Emergency SOS feature in the app.']
                    ]
                ],
                'contents' => [
                    [
                        'parts' => [
                            ['text' => $userMessage]
                        ]
                    ]
                ]
            ]);

            if ($response->successful()) {
                $data = $response->json();
                return $data['candidates'][0]['content']['parts'][0]['text'] ?? 'Sorry, I could not understand the response.';
            }

            Log::error('Gemini API Error: ' . $response->body());
            return 'I am currently experiencing technical difficulties. Please try again later.';
        } catch (\Exception $e) {
            Log::error('Gemini API Exception: ' . $e->getMessage());
            return 'I am currently experiencing technical difficulties. Please try again later.';
        }
    }
}
