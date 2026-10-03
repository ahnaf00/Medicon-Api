<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\AiChatSession;
use App\Models\Appointment;
use App\Services\ConsultationChatService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\StreamedEvent;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

class ConsultationChatController extends Controller
{
    private const INTERRUPTED_MARKER = "\n\n[Response interrupted]";

    /**
     * POST /ai/consultation-chat — streams (SSE) an answer grounded in one
     * completed consultation. Events: `session` {sessionId}, `delta` {text},
     * then `done` {messageId} or `error` {message}.
     */
    public function chat(Request $request, ConsultationChatService $chat): StreamedResponse|JsonResponse
    {
        $validated = $request->validate([
            'appointment_id' => ['required', 'integer'],
            'message'        => ['required', 'string', 'max:2000'],
        ]);

        $appointment = Appointment::with('consultationSummary', 'doctor')->findOrFail($validated['appointment_id']);

        Gate::authorize('consultationChat', $appointment);

        // Without the doctor's summary there is nothing to ground an answer in.
        if ($appointment->status !== 'completed' || ! $appointment->consultationSummary) {
            return response()->json([
                'message' => 'This consultation has no summary from your doctor yet, so the AI cannot answer questions about it.',
            ], 409);
        }

        $session = AiChatSession::firstOrCreate(
            ['user_id' => $request->user()->id, 'appointment_id' => $appointment->id],
            ['title' => 'Consultation with '.($appointment->doctor?->name ?? 'your doctor')],
        );

        $history = $session->messages()
            ->latest('id')
            ->limit(ConsultationChatService::HISTORY_LIMIT)
            ->get()
            ->reverse()
            ->values();

        $session->messages()->create(['role' => 'user', 'content' => $validated['message']]);
        $session->touch();

        return response()->eventStream(function () use ($chat, $appointment, $session, $history, $validated) {
            yield new StreamedEvent('session', ['sessionId' => $session->id]);

            $reply = '';
            try {
                foreach ($chat->stream($appointment, $history, $validated['message']) as $delta) {
                    $reply .= $delta;
                    yield new StreamedEvent('delta', ['text' => $delta]);
                }

                if (trim($reply) === '') {
                    throw new \RuntimeException('Gemini returned an empty reply.');
                }

                $message = $session->messages()->create(['role' => 'assistant', 'content' => $reply]);

                yield new StreamedEvent('done', ['messageId' => $message->id]);
            } catch (Throwable $e) {
                Log::error('Consultation chat failed: '.$e->getMessage(), ['appointment_id' => $appointment->id]);

                // Keep what the patient already saw, marked as cut off.
                if (trim($reply) !== '') {
                    $session->messages()->create(['role' => 'assistant', 'content' => $reply.self::INTERRUPTED_MARKER]);
                }

                yield new StreamedEvent('error', [
                    'message' => 'The AI assistant is unavailable right now. Please try again in a moment.',
                ]);
            }
        }, endStreamWith: null);
    }
}
