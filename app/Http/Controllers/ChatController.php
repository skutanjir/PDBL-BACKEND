<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\Team;
use App\Models\User;
use App\Jobs\SendPushNotification;
use Illuminate\Http\Request;

class ChatController extends Controller
{
    public function conversations(Request $request)
    {
        $user = auth('api')->user();

        $teams = $user->teams()
            ->wherePivot('status', 'accepted')
            ->with(['members' => function ($query) {
                $query->wherePivot('status', 'accepted');
            }])
            ->get();

        $teamConversations = $teams->map(function (Team $team) use ($user) {
            $conversation = ChatConversation::firstOrCreate([
                'type' => 'team',
                'team_id' => $team->id,
            ]);

            $conversation->participants()->syncWithoutDetaching(
                $team->members->pluck('id')->all()
            );

            $lastMessage = $conversation->messages()
                ->with('sender:id,name,avatar')
                ->latest()
                ->first();

            $unreadCount = $this->getUnreadCount($conversation, $user);

            return [
                'id' => $conversation->id,
                'type' => 'team',
                'team_id' => $team->id,
                'name' => $team->name,
                'avatar_url' => $team->avatar_url,
                'members' => $team->members->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar_url' => $member->avatar_url,
                ])->values(),
                'last_message' => $lastMessage ? [
                    'body' => $lastMessage->body,
                    'sender_name' => $lastMessage->sender?->name,
                    'created_at' => $lastMessage->created_at?->toIso8601String(),
                ] : null,
                'updated_at' => ($lastMessage?->created_at ?? $conversation->updated_at)?->toIso8601String(),
                'unread_count' => $unreadCount,
            ];
        });

        $personalConversations = $user->chatConversations()
            ->where('type', 'personal')
            ->with('participants')
            ->get()
            ->map(function (ChatConversation $conversation) use ($user) {
                $other = $conversation->participants->firstWhere('id', '!=', $user->id);
                $lastMessage = $conversation->messages()
                    ->with('sender:id,name,avatar')
                    ->latest()
                    ->first();
                $unreadCount = $this->getUnreadCount($conversation, $user);

                return [
                    'id' => $conversation->id,
                    'type' => 'personal',
                    'team_id' => null,
                    'name' => $other?->name ?? 'Private Chat',
                    'avatar_url' => $other?->avatar_url,
                    'members' => $conversation->participants->map(fn ($member) => [
                        'id' => $member->id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'avatar_url' => $member->avatar_url,
                    ])->values(),
                    'last_message' => $lastMessage ? [
                        'body' => $lastMessage->body,
                        'sender_name' => $lastMessage->sender?->name,
                        'created_at' => $lastMessage->created_at?->toIso8601String(),
                    ] : null,
                    'updated_at' => ($lastMessage?->created_at ?? $conversation->updated_at)?->toIso8601String(),
                    'unread_count' => $unreadCount,
                ];
            });

        $conversations = $teamConversations->concat($personalConversations)
            ->sortByDesc('updated_at')
            ->values();

        return response()->json(['conversations' => $conversations]);
    }

    public function markRead(ChatConversation $conversation)
    {
        $user = auth('api')->user();

        if ($conversation->type === 'team') {
            $this->authorizeConversation($conversation);
        } elseif (!$this->authorizePersonalConversation($conversation)) {
            abort(403, 'Unauthorized');
        }

        $conversation->participants()->syncWithoutDetaching([
            $user->id => ['last_read_at' => now()],
        ]);

        return response()->json(['message' => 'Conversation marked as read']);
    }

    public function messages(ChatConversation $conversation)
    {
        if ($conversation->type === 'team') {
            $this->authorizeConversation($conversation);
        } elseif (!$this->authorizePersonalConversation($conversation)) {
            abort(403, 'Unauthorized');
        }

        $messages = $conversation->messages()
            ->with('sender:id,name,email,avatar')
            ->orderBy('created_at')
            ->limit(100)
            ->get()
            ->map(fn ($message) => $this->formatMessage($message))
            ->values();

        return response()->json(['messages' => $messages]);
    }

    public function send(Request $request, ChatConversation $conversation)
    {
        $user = auth('api')->user();
        $team = null;
        if ($conversation->type === 'team') {
            $team = $this->authorizeConversation($conversation);
        } elseif (!$this->authorizePersonalConversation($conversation)) {
            abort(403, 'Unauthorized');
        }

        $data = $request->validate([
            'body' => 'required|string|max:2000',
        ]);

        $body = trim($data['body']);
        $mentionsAll = preg_match('/(^|\s)@all\b/i', $body) === 1;
        $mentionedUserIds = [];
        if ($team) {
            $mentionedUserIds = $team->members()
                ->wherePivot('status', 'accepted')
                ->get()
                ->filter(function ($member) use ($body) {
                    return preg_match('/(^|\s)@' . preg_quote($member->name, '/') . '\b/i', $body) === 1;
                })
                ->pluck('id')
                ->values()
                ->all();
        }

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'body' => $body,
            'mentions_all' => $mentionsAll,
            'mentioned_user_ids' => $mentionedUserIds,
        ])->load('sender:id,name,email,avatar');

        $conversation->touch();

        $conversation->participants()->syncWithoutDetaching([
            $user->id => ['last_read_at' => now()],
        ]);

        // Dispatch FCM notifications
        $title = $team ? $team->name : $user->name;
        $notificationBody = $body;

        if ($team) {
            $recipients = $team->members()->where('user_id', '!=', $user->id)->wherePivot('status', 'accepted')->get();
        } else {
            $recipients = $conversation->participants()->where('user_id', '!=', $user->id)->get();
        }

        foreach ($recipients as $recipient) {
            SendPushNotification::dispatch(
                $recipient,
                $title,
                $notificationBody,
                [
                    'conversation_id' => (string)$conversation->id,
                    'type' => 'chat',
                    'chat_type' => $conversation->type,
                    'conversation_type' => $conversation->type,
                    'team_id' => (string)($conversation->team_id ?? ''),
                    'sender_id' => (string)$user->id,
                    'sender_name' => $user->name,
                    'conversation_name' => $title,
                    'body' => $body,
                ]
            );
        }

        return response()->json(['message' => $this->formatMessage($message)], 201);
    }

    public function startPrivate(User $user)
    {
        $currentUser = auth('api')->user();

        if ($currentUser->id === $user->id) {
            return response()->json(['message' => 'You cannot start a private chat with yourself.'], 422);
        }

        $conversation = ChatConversation::where('type', 'personal')
            ->whereHas('participants', fn ($query) => $query->where('user_id', $currentUser->id))
            ->whereHas('participants', fn ($query) => $query->where('user_id', $user->id))
            ->first();

        if (!$conversation) {
            $conversation = ChatConversation::create(['type' => 'personal']);
            $conversation->participants()->attach([
                $currentUser->id => ['last_read_at' => now()],
                $user->id => ['last_read_at' => null],
            ]);
        }

        $conversation->load('participants');

        return response()->json([
            'conversation' => [
                'id' => $conversation->id,
                'type' => 'personal',
                'team_id' => null,
                'name' => $user->name,
                'avatar_url' => $user->avatar_url,
                'members' => $conversation->participants->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar_url' => $member->avatar_url,
                ])->values(),
                'last_message' => null,
                'updated_at' => $conversation->updated_at?->toIso8601String(),
                'unread_count' => $this->getUnreadCount($conversation, $currentUser),
            ],
        ]);
    }

    private function getUnreadCount(ChatConversation $conversation, User $user): int
    {
        $pivot = $conversation->participants()
            ->where('user_id', $user->id)
            ->first()?->pivot;

        $lastReadAt = $pivot?->last_read_at;

        return $conversation->messages()
            ->where('sender_id', '!=', $user->id)
            ->when($lastReadAt, fn ($query) => $query->where('created_at', '>', $lastReadAt))
            ->count();
    }

    private function authorizeConversation(ChatConversation $conversation): Team
    {
        $user = auth('api')->user();
        $team = $conversation->team;

        if (!$team || !$team->members()->where('user_id', $user->id)->where('status', 'accepted')->exists()) {
            abort(403, 'Unauthorized');
        }

        return $team;
    }

    private function authorizePersonalConversation(ChatConversation $conversation): bool
    {
        $user = auth('api')->user();
        return $conversation->participants()->where('user_id', $user->id)->exists();
    }

    private function formatMessage($message): array
    {
        return [
            'id' => $message->id,
            'conversation_id' => $message->chat_conversation_id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name,
            'sender_email' => $message->sender?->email,
            'sender_avatar_url' => $message->sender?->avatar_url,
            'body' => $message->body,
            'mentions_all' => $message->mentions_all,
            'mentioned_user_ids' => $message->mentioned_user_ids ?? [],
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
