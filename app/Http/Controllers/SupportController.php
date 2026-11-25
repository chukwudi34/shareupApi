<?php

namespace App\Http\Controllers;

use App\Models\Notification;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Support\Presenter;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportController extends Controller
{
    private const CATEGORIES = ['GENERAL', 'PAYMENT', 'OPPORTUNITY', 'ACCOUNT', 'COMPLAINT'];

    /** The signed-in user's open support conversation, if one exists. */
    public function conversation(Request $request): JsonResponse
    {
        $conversation = SupportConversation::with(['user', 'messages.sender'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'CLOSED')
            ->latest('last_message_at')
            ->first();

        if ($conversation) {
            $conversation->messages()
                ->where('sender_type', 'SUPPORT')
                ->whereNull('read_at')
                ->update(['read_at' => now()]);
            $conversation->load('messages.sender');
        }

        return response()->json([
            'data' => $conversation ? Presenter::supportConversation($conversation, true) : null,
        ]);
    }

    /** Start a conversation, or append to the existing open thread. */
    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'category' => ['nullable', 'in:'.implode(',', self::CATEGORIES)],
            'subject' => ['nullable', 'string', 'max:160'],
            'message' => ['required', 'string', 'min:2', 'max:2000'],
        ]);

        $conversation = SupportConversation::with(['user', 'messages.sender'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'CLOSED')
            ->latest('last_message_at')
            ->first();

        $created = false;

        if (! $conversation) {
            $conversation = DB::transaction(function () use ($request, $data) {
                $conversation = SupportConversation::create([
                    'reference' => 'SUP-'.strtoupper(Str::random(8)),
                    'user_id' => $request->user()->id,
                    'category' => $data['category'] ?? 'GENERAL',
                    'subject' => $data['subject'] ?? null,
                    'status' => 'OPEN',
                ]);

                SupportMessage::create([
                    'support_conversation_id' => $conversation->id,
                    'sender_id' => null,
                    'sender_type' => 'SYSTEM',
                    'body' => 'Thanks for reaching out. Your message is with the ShareUp support team and we reply within one business day. Reference: '.$conversation->reference.'.',
                ]);

                return $conversation;
            });

            $created = true;
        }

        $message = SupportMessage::create([
            'support_conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'sender_type' => 'USER',
            'body' => $data['message'],
        ]);

        $conversation->update([
            'status' => 'OPEN',
            'last_message_at' => $message->created_at ?? now(),
        ]);

        $conversation->load('messages.sender');

        return response()->json(
            ['data' => Presenter::supportConversation($conversation, true)],
            $created ? 201 : 200
        );
    }

    public function reply(Request $request): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $conversation = SupportConversation::with(['user', 'messages.sender'])
            ->where('user_id', $request->user()->id)
            ->where('status', '!=', 'CLOSED')
            ->latest('last_message_at')
            ->first();

        abort_unless($conversation, 404, 'Start a conversation before replying.');

        $message = SupportMessage::create([
            'support_conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'sender_type' => 'USER',
            'body' => $data['message'],
        ]);

        $conversation->update([
            'status' => 'OPEN',
            'last_message_at' => $message->created_at ?? now(),
        ]);

        $conversation->load('messages.sender');

        return response()->json(['data' => Presenter::supportConversation($conversation, true)], 201);
    }

    public function adminIndex(Request $request): JsonResponse
    {
        $items = SupportConversation::with(['user', 'messages.sender'])
            ->when($request->filled('status'), fn ($query) => $query->where('status', $request->string('status')->toString()))
            ->orderByRaw('last_message_at IS NULL')
            ->orderByDesc('last_message_at')
            ->limit(100)
            ->get()
            ->map(fn (SupportConversation $conversation) => Presenter::supportConversation($conversation, true));

        return response()->json([
            'data' => $items,
            'meta' => [
                'open' => SupportConversation::where('status', 'OPEN')->count(),
                'pending' => SupportConversation::where('status', 'PENDING')->count(),
            ],
        ]);
    }

    public function adminReply(Request $request, SupportConversation $conversation): JsonResponse
    {
        $data = $request->validate([
            'message' => ['required', 'string', 'min:1', 'max:2000'],
        ]);

        $message = SupportMessage::create([
            'support_conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'sender_type' => 'SUPPORT',
            'body' => $data['message'],
        ]);

        $conversation->messages()
            ->where('sender_type', 'USER')
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->update([
            'status' => 'PENDING',
            'last_message_at' => $message->created_at ?? now(),
        ]);

        Notification::sendTo(
            $conversation->user_id,
            'Support replied to '.$conversation->reference,
            Str::limit($message->body, 140),
            'SUPPORT'
        );

        $conversation->load(['user', 'messages.sender']);

        return response()->json(['data' => Presenter::supportConversation($conversation, true)], 201);
    }

    public function adminClose(Request $request, SupportConversation $conversation): JsonResponse
    {
        abort_if($conversation->status === 'CLOSED', 422, 'This conversation is already closed.');

        $conversation->update(['status' => 'CLOSED', 'closed_at' => now()]);

        SupportMessage::create([
            'support_conversation_id' => $conversation->id,
            'sender_id' => $request->user()->id,
            'sender_type' => 'SYSTEM',
            'body' => 'This conversation was closed by the ShareUp support team. Start a new chat if you need anything else.',
        ]);

        $conversation->messages()
            ->whereNull('read_at')
            ->update(['read_at' => now()]);

        $conversation->load(['user', 'messages.sender']);

        return response()->json(['data' => Presenter::supportConversation($conversation, true)]);
    }
}
