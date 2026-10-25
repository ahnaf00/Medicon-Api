<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\ConversationResource;
use App\Models\Appointment;
use App\Models\Conversation;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

/**
 * Direct doctor-patient chat (audit M1). Built on conversations + messages:
 * one `direct` conversation per patient-doctor pair, open only to a patient
 * and doctor who have a visit together. Messages use the conversation
 * message endpoints; the app polls them.
 */
class DirectChatController extends Controller
{
    /** Visits that make two people each other's patient and doctor. */
    private const CARE_STATUSES = ['scheduled', 'in_progress', 'completed'];

    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $chats = Conversation::where('type', Conversation::TYPE_DIRECT)
            ->where(fn ($q) => $q->where('patient_user_id', $user->id)->orWhere('doctor_user_id', $user->id))
            ->with(['patient', 'doctor.doctorProfile', 'latestMessage'])
            ->withCount(['messages as unread_count' => fn ($q) => $q
                ->where('sender_user_id', '!=', $user->id)
                ->whereNull('read_at')])
            ->withMax('messages', 'created_at')
            ->orderByDesc('messages_max_created_at')
            ->orderByDesc('id')
            ->paginate(20);

        return ConversationResource::collection($chats);
    }

    /**
     * Opens (or returns) the chat with `user_id`. Allowed only when the two
     * share a visit; who is the patient is taken from that visit.
     */
    public function open(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
        ]);

        $me = $request->user()->id;
        $other = (int) $validated['user_id'];

        $visit = Appointment::whereIn('status', self::CARE_STATUSES)
            ->where(fn ($q) => $q
                ->where(fn ($q) => $q->where('patient_user_id', $me)->where('doctor_user_id', $other))
                ->orWhere(fn ($q) => $q->where('patient_user_id', $other)->where('doctor_user_id', $me)))
            ->first();

        if ($other === $me || ! $visit) {
            return response()->json([
                'message' => 'You can message a doctor after booking a visit with them.',
            ], 403);
        }

        $pair = "{$visit->patient_user_id}-{$visit->doctor_user_id}";
        $attributes = [
            'type' => Conversation::TYPE_DIRECT,
            'patient_user_id' => $visit->patient_user_id,
            'doctor_user_id' => $visit->doctor_user_id,
            'status' => 'open',
        ];

        try {
            $chat = Conversation::firstOrCreate(['direct_pair' => $pair], $attributes);
        } catch (UniqueConstraintViolationException) {
            // Both sides opened it at the same moment: the other request created it.
            $chat = Conversation::where('direct_pair', $pair)->firstOrFail();
        }

        return response()->json([
            'conversation' => new ConversationResource($chat->load(['patient', 'doctor.doctorProfile', 'latestMessage'])),
        ], $chat->wasRecentlyCreated ? 201 : 200);
    }
}
