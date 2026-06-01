<?php

namespace App\Http\Controllers;

use App\Models\ChatConversation;
use App\Models\ChatMessage;
use App\Models\Team;
use App\Models\User;
use App\Jobs\SendPushNotification;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\Rule;

class ChatController extends Controller
{
    private const MESSAGE_MAX_LENGTH = 65536;
    private const MESSAGE_EDIT_MINUTES = 15;
    private const MESSAGE_DELETE_MINUTES = 60;
    private const MAX_MENTIONS = 10;
    private const SEND_COOLDOWN_SECONDS = 1;

    public function conversations(Request $request)
    {
        $user = auth('api')->user();

        $teams = Cache::remember("chat:user:{$user->id}:teams", now()->addSeconds(30), fn () => $user->teams()
            ->wherePivot('status', 'accepted')
            ->with(['members' => function ($query) {
                $query->wherePivot('status', 'accepted');
            }])
            ->get());

        $teamConversations = $teams->map(function (Team $team) use ($user) {
            $conversation = ChatConversation::firstOrCreate([
                'type' => 'team',
                'team_id' => $team->id,
            ]);

            $conversation->participants()->syncWithoutDetaching(
                $team->members->pluck('id')->all()
            );

            $lastMessage = Cache::remember("chat:conversation:{$conversation->id}:last_message", now()->addSeconds(20), fn () => $conversation->messages()
                ->with('sender:id,name,avatar')
                ->latest()
                ->first());

            $unreadCount = $this->getUnreadCount($conversation, $user);

            return [
                'id' => $conversation->id,
                'type' => 'team',
                'team_id' => $team->id,
                'name' => $team->name,
                'avatar_url' => $team->avatar_url,
                'can_moderate_messages' => (int) $team->created_by === (int) $user->id,
                'members' => $team->members->map(fn ($member) => [
                    'id' => $member->id,
                    'name' => $member->name,
                    'email' => $member->email,
                    'avatar_url' => $member->avatar_url,
                ])->values(),
                'last_message' => $lastMessage ? [
                    'body' => $lastMessage->deleted_at ? 'This message was deleted' : $lastMessage->body,
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
                $lastMessage = Cache::remember("chat:conversation:{$conversation->id}:last_message", now()->addSeconds(20), fn () => $conversation->messages()
                    ->with('sender:id,name,avatar')
                    ->latest()
                    ->first());
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
                        'body' => $lastMessage->deleted_at ? 'This message was deleted' : $lastMessage->body,
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

    public function messages(Request $request, ChatConversation $conversation)
    {
        $user = auth('api')->user();
        if ($conversation->type === 'team') {
            $this->authorizeConversation($conversation);
        } elseif (!$this->authorizePersonalConversation($conversation)) {
            abort(403, 'Unauthorized');
        }

        $hiddenIds = Schema::hasTable('chat_message_user_deletions')
            ? DB::table('chat_message_user_deletions')
                ->where('user_id', $user->id)
                ->pluck('chat_message_id')
                ->all()
            : [];

        $limit = min(max((int) $request->query('limit', 100), 1), 100);
        $beforeId = $request->query('before_id');

        $query = $conversation->messages()
            ->whereNotIn('id', $hiddenIds)
            ->with(['sender:id,name,email,avatar', 'replyTo.sender:id,name'])
            ->when($beforeId, fn ($query) => $query->where('id', '<', (int) $beforeId))
            ->orderByDesc('created_at')
            ->orderByDesc('id');

        $page = $query->limit($limit + 1)->get();
        $hasMore = $page->count() > $limit;

        $messages = $page
            ->take($limit)
            ->reverse()
            ->map(fn ($message) => $this->formatMessage($message))
            ->values();

        return response()->json([
            'messages' => $messages,
            'has_more' => $hasMore,
        ]);
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

        $cooldownKey = "chat:send:cooldown:{$user->id}:{$conversation->id}";
        if (Cache::has($cooldownKey)) {
            return response()->json(['message' => 'Please wait before sending another message.'], 429);
        }

        $data = $request->validate([
            'body' => 'required|string|max:' . self::MESSAGE_MAX_LENGTH,
            'reply_to_id' => [
                'nullable',
                'integer',
                Rule::exists('chat_messages', 'id')->where('chat_conversation_id', $conversation->id),
            ],
            'client_nonce' => 'nullable|string|max:80',
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        $duplicateKey = 'chat:message:duplicate:' . sha1($user->id . '|' . $conversation->id . '|' . ($data['client_nonce'] ?? $body));
        if (!Cache::add($duplicateKey, true, now()->addSeconds(10))) {
            return response()->json(['message' => 'Duplicate message ignored.'], 409);
        }

        Cache::put($cooldownKey, true, now()->addSeconds(self::SEND_COOLDOWN_SECONDS));

        [$mentionsAll, $mentionedUserIds] = $this->parseMentions($body, $team);
        if (count($mentionedUserIds) > self::MAX_MENTIONS) {
            return response()->json(['message' => 'Too many mentions in one message.'], 422);
        }

        $message = $conversation->messages()->create([
            'sender_id' => $user->id,
            'reply_to_id' => $data['reply_to_id'] ?? null,
            'body' => $body,
            'mentions_all' => $mentionsAll,
            'mentioned_user_ids' => $mentionedUserIds,
        ])->load(['sender:id,name,email,avatar', 'replyTo.sender:id,name']);

        $conversation->touch();
        $this->invalidateConversationCache($conversation, $user);

        $conversation->participants()->syncWithoutDetaching([
            $user->id => ['last_read_at' => now()],
        ]);

        // Dispatch FCM notifications
        $title = $team ? $team->name : $user->name;
        $notificationBody = $body;

        if ($team) {
            $recipients = $team->members()->where('users.id', '!=', $user->id)->wherePivot('status', 'accepted')->get();
        } else {
            $recipients = $conversation->participants()->where('user_id', '!=', $user->id)->get();
        }

        foreach ($recipients as $recipient) {
            $notificationKey = "chat:fcm:{$conversation->id}:{$message->id}:{$recipient->id}";
            if (!Cache::add($notificationKey, true, now()->addMinutes(10))) {
                continue;
            }

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

    public function editMessage(Request $request, ChatConversation $conversation, ChatMessage $message)
    {
        $user = auth('api')->user();
        $this->authorizeMessageAccess($conversation, $message);

        if ($message->sender_id !== $user->id) {
            abort(403, 'You can edit only your own messages.');
        }

        if ($message->deleted_at) {
            return response()->json(['message' => 'Deleted messages cannot be edited.'], 422);
        }

        if ($message->created_at->lt(now()->subMinutes(self::MESSAGE_EDIT_MINUTES))) {
            return response()->json(['message' => 'Message edit window has expired.'], 422);
        }

        $data = $request->validate([
            'body' => 'required|string|max:' . self::MESSAGE_MAX_LENGTH,
        ]);

        $body = trim($data['body']);
        if ($body === '') {
            return response()->json(['message' => 'Message cannot be empty.'], 422);
        }

        $team = $conversation->type === 'team' ? $conversation->team : null;
        [$mentionsAll, $mentionedUserIds] = $this->parseMentions($body, $team);
        if (count($mentionedUserIds) > self::MAX_MENTIONS) {
            return response()->json(['message' => 'Too many mentions in one message.'], 422);
        }

        $message->update([
            'body' => $body,
            'mentions_all' => $mentionsAll,
            'mentioned_user_ids' => $mentionedUserIds,
            'edited_at' => now(),
        ]);

        $message->load(['sender:id,name,email,avatar', 'replyTo.sender:id,name']);
        $this->invalidateConversationCache($conversation, $user);

        return response()->json(['message' => $this->formatMessage($message)]);
    }

    public function deleteMessage(Request $request, ChatConversation $conversation, ChatMessage $message)
    {
        $user = auth('api')->user();
        $team = $this->authorizeMessageAccess($conversation, $message);

        $data = $request->validate([
            'scope' => 'required|in:me,everyone',
        ]);

        if ($data['scope'] === 'me') {
            DB::table('chat_message_user_deletions')->updateOrInsert([
                'chat_message_id' => $message->id,
                'user_id' => $user->id,
            ], [
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->invalidateConversationCache($conversation, $user);

            return response()->json(['message' => 'Message erased for you.']);
        }

        $isLeader = $team && (int) $team->created_by === (int) $user->id;
        if ($message->sender_id !== $user->id && !$isLeader) {
            abort(403, 'You can delete only your own messages.');
        }

        if (!$isLeader && $message->created_at->lt(now()->subMinutes(self::MESSAGE_DELETE_MINUTES))) {
            return response()->json(['message' => 'Message delete window has expired.'], 422);
        }

        $message->update([
            'body' => '',
            'deleted_at' => now(),
            'deleted_by_id' => $user->id,
            'delete_reason' => $isLeader && $message->sender_id !== $user->id ? 'leader' : 'sender',
        ]);

        $message->load(['sender:id,name,email,avatar', 'replyTo.sender:id,name']);
        $this->invalidateConversationCache($conversation, $user);

        return response()->json(['message' => $this->formatMessage($message)]);
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

        if (!$team || !$team->members()->where('users.id', $user->id)->wherePivot('status', 'accepted')->exists()) {
            abort(403, 'Unauthorized');
        }

        return $team;
    }

    private function authorizePersonalConversation(ChatConversation $conversation): bool
    {
        $user = auth('api')->user();
        return $conversation->participants()->where('user_id', $user->id)->exists();
    }

    private function authorizeMessageAccess(ChatConversation $conversation, ChatMessage $message): ?Team
    {
        if ((int) $message->chat_conversation_id !== (int) $conversation->id) {
            abort(404);
        }

        if ($conversation->type === 'team') {
            return $this->authorizeConversation($conversation);
        }

        if (!$this->authorizePersonalConversation($conversation)) {
            abort(403, 'Unauthorized');
        }

        return null;
    }

    private function parseMentions(string $body, ?Team $team): array
    {
        $mentionsAll = preg_match('/(^|\s)@all\b/i', $body) === 1;
        if (!$team) {
            return [$mentionsAll, []];
        }

        preg_match_all('/(^|\s)@([\pL\pN._-]+)/u', $body, $matches);
        $tokens = collect($matches[2] ?? [])
            ->map(fn ($token) => mb_strtolower($token))
            ->reject(fn ($token) => $token === 'all')
            ->unique()
            ->values();

        if ($tokens->isEmpty()) {
            return [$mentionsAll, []];
        }

        $members = Cache::remember("chat:team:{$team->id}:members", now()->addMinutes(5), fn () => $team->members()
            ->wherePivot('status', 'accepted')
            ->get(['users.id', 'users.name', 'users.email']));

        $mentionedUserIds = $members
            ->filter(function ($member) use ($tokens) {
                $firstName = mb_strtolower(strtok($member->name, ' ') ?: $member->name);
                $fullName = mb_strtolower(str_replace(' ', '', $member->name));

                return $tokens->contains($firstName) || $tokens->contains($fullName);
            })
            ->pluck('id')
            ->values()
            ->all();

        return [$mentionsAll, $mentionedUserIds];
    }

    private function invalidateConversationCache(ChatConversation $conversation, User $user): void
    {
        Cache::forget("chat:conversation:{$conversation->id}:last_message");
        Cache::forget("chat:user:{$user->id}:teams");
        if ($conversation->team_id) {
            Cache::forget("chat:team:{$conversation->team_id}:members");
        }
    }

    private function formatMessage($message): array
    {
        $deletedLabel = match ($message->delete_reason) {
            'leader' => 'Message deleted by leader',
            'admin' => 'Deleted by admin',
            default => 'This message was deleted',
        };

        return [
            'id' => $message->id,
            'conversation_id' => $message->chat_conversation_id,
            'sender_id' => $message->sender_id,
            'sender_name' => $message->sender?->name,
            'sender_email' => $message->sender?->email,
            'sender_avatar_url' => $message->sender?->avatar_url,
            'body' => $message->deleted_at ? $deletedLabel : $message->body,
            'mentions_all' => $message->mentions_all,
            'mentioned_user_ids' => $message->mentioned_user_ids ?? [],
            'reply_to_id' => $message->reply_to_id,
            'reply_sender_name' => $message->replyTo?->sender?->name,
            'reply_body' => $message->replyTo?->deleted_at ? 'This message was deleted' : $message->replyTo?->body,
            'edited_at' => $message->edited_at?->toIso8601String(),
            'deleted_at' => $message->deleted_at?->toIso8601String(),
            'delete_reason' => $message->delete_reason,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }
}
