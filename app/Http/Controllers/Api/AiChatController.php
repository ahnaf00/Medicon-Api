<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\AiChatMessageResource;
use App\Http\Resources\AiChatSessionResource;
use App\Models\AiChatSession;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

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

        // === PLACEHOLDER: Replace this block with real LLM API call ===
        $reply = $this->generateReply($request->message);
        // ==============================================================

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

    // =====================================================================
    // PLACEHOLDER ENGINE — swap with OpenAI / Gemini client when ready
    // =====================================================================
    private function generateReply(string $userMessage): string
    {
        $msg = strtolower($userMessage);
        if (str_contains($msg, 'appointment'))
            return 'You can book an appointment through the Schedule tab. Would you like me to help you find a suitable doctor?';
        if (str_contains($msg, 'prescription'))
            return 'Prescriptions are issued by your doctor after a consultation. Please book an appointment to get started.';
        if (str_contains($msg, 'emergency'))
            return 'If this is an emergency, please call your local emergency services immediately or use the Emergency SOS feature in the app.';
        return 'I understand your concern. For accurate medical advice, please consult with one of our qualified doctors. Can I help you book an appointment?';
    }
}
