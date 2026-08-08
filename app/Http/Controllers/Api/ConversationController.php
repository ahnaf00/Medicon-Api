<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Api\Conversations\SendMessageRequest;
use App\Http\Requests\Api\Conversations\StoreConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ConversationController extends Controller
{
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        
        $query = Conversation::with(['patient', 'doctor', 'latestMessage', 'firstMessage']);
        
        if ($request->has('department')) {
            // Doctor fetching inbox
            $query->where(function ($q) use ($request, $user) {
                $q->where('department', $request->department)
                  ->whereNull('doctor_user_id')
                  ->orWhere('doctor_user_id', $user->id);
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
        $conversation = Conversation::where('id', $id)
            ->where(function($q) use ($user) {
                $q->where('patient_user_id', $user->id)
                  ->orWhere('doctor_user_id', $user->id)
                  ->orWhereNull('doctor_user_id');
            })
            ->firstOrFail();

        // Mark unread messages as read for the current user
        $conversation->messages()
            ->where('sender_user_id', '!=', $user->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $messages = $conversation->messages()->with('sender')->oldest()->paginate(50);
        return MessageResource::collection($messages);
    }

    public function sendMessage(SendMessageRequest $request, $id): JsonResponse
    {
        $user = $request->user();
        $conversation = Conversation::where('id', $id)
            ->where(function($q) use ($user) {
                $q->where('patient_user_id', $user->id)
                  ->orWhere('doctor_user_id', $user->id)
                  ->orWhereNull('doctor_user_id');
            })
            ->firstOrFail();

        // If a doctor answers an unassigned question, they claim it
        if ($conversation->patient_user_id !== $user->id && !$conversation->doctor_user_id) {
            $conversation->update(['doctor_user_id' => $user->id]);
        }

        $message = $conversation->messages()->create([
            'sender_user_id' => $user->id,
            'body'           => $request->validated('body'),
        ]);

        return response()->json([
            'message' => 'Message sent.',
            'data'    => new MessageResource($message->load('sender')),
        ], 201);
    }
}
