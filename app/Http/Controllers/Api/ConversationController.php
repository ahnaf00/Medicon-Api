<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Conversations\SendMessageRequest;
use App\Http\Requests\Api\Conversations\StoreConversationRequest;
use App\Http\Requests\Api\Conversations\UpdateConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        
        $query = Conversation::with(['patient', 'doctor', 'latestMessage', 'firstMessage'])
            ->withCount(['messages as reply_count' => fn ($q) => $q->whereColumn('messages.sender_user_id', '!=', 'conversations.patient_user_id')]);
        
        if ($user->hasRole('doctor')) {
            // Doctor inbox: questions assigned to them, plus unassigned ones in their
            // own specialty once verified. The client's ?department is not trusted.
            $query->where(function ($q) use ($user) {
                $q->where('doctor_user_id', $user->id);

                if ($user->isVerifiedDoctor()) {
                    $q->orWhere(function ($q) use ($user) {
                        $q->whereNull('doctor_user_id')
                          ->where('department', $user->doctorProfile->specialty);
                    });
                }
            });
        } else {
            // Patient fetching their questions
            $query->where('patient_user_id', $user->id);
        }

        $conversations = $query->latest()->paginate(20);

        return ConversationResource::collection($conversations);
    }

    public function store(StoreConversationRequest $request): JsonResponse
    {
        $conversation = Conversation::create([
            'patient_user_id' => $request->user()->id,
            'doctor_user_id'  => $request->validated('doctor_user_id'),
            'department'      => $request->validated('department'),
            'subject'         => $request->validated('subject'),
            'is_anonymous'    => $request->boolean('is_anonymous'),
            'status'          => 'open'
        ]);

        return response()->json([
            'message'      => 'Conversation started.',
            'conversation' => new ConversationResource($conversation->load(['patient', 'doctor', 'firstMessage', 'latestMessage'])),
        ], 201);
    }

    public function messages(Request $request, $id): AnonymousResourceCollection
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        Gate::authorize('view', $conversation);

        // Mark unread messages as read for the current user
        $conversation->messages()
            ->where('sender_user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = $conversation->messages()->with('sender')->oldest()->paginate(50);
        $messages->getCollection()->each->setRelation('conversation', $conversation);

        return MessageResource::collection($messages);
    }

    /**
     * The asking patient edits their question while no one has replied yet.
     */
    public function update(UpdateConversationRequest $request, $id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        Gate::authorize('update', $conversation);

        $validated = $request->validated();

        DB::transaction(function () use ($conversation, $validated) {
            $conversation->fill(array_intersect_key($validated, array_flip(['department', 'is_anonymous'])));

            if (array_key_exists('body', $validated)) {
                $conversation->subject = mb_substr($validated['body'], 0, 255);
                $conversation->firstMessage?->update(['body' => $validated['body']]);
            }

            $conversation->save();
        });

        return response()->json([
            'message'      => 'Question updated.',
            'conversation' => new ConversationResource($conversation->fresh(['patient', 'doctor', 'firstMessage', 'latestMessage'])),
        ]);
    }

    /**
     * The asking patient withdraws their question while no one has replied yet.
     */
    public function destroy($id): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);

        Gate::authorize('delete', $conversation);

        $conversation->delete();

        return response()->json(['message' => 'Question deleted.']);
    }

    /**
     * The sender edits their own message (e.g. a doctor revising an answer).
     */
    public function updateMessage(SendMessageRequest $request, $id, $messageId): JsonResponse
    {
        $conversation = Conversation::findOrFail($id);
        $message = Message::where('conversation_id', $conversation->id)->findOrFail($messageId);

        Gate::authorize('updateMessage', [$conversation, $message]);

        $message->update(['body' => $request->validated('body')]);
        $message->setRelation('conversation', $conversation);

        return response()->json([
            'message' => 'Message updated.',
            'data'    => new MessageResource($message->load('sender')),
        ]);
    }

    public function sendMessage(SendMessageRequest $request, $id): JsonResponse
    {
        $user = $request->user();
        $conversation = Conversation::findOrFail($id);

        Gate::authorize('reply', $conversation);

        // If a doctor answers an unassigned question, they claim it
        if ($conversation->patient_user_id !== $user->id && !$conversation->doctor_user_id) {
            $conversation->update(['doctor_user_id' => $user->id]);
        }

        $message = $conversation->messages()->create([
            'sender_user_id' => $user->id,
            'body'           => $request->validated('body'),
        ]);
        $message->setRelation('conversation', $conversation);

        return response()->json([
            'message' => 'Message sent.',
            'data'    => new MessageResource($message->load('sender')),
        ], 201);
    }
}
