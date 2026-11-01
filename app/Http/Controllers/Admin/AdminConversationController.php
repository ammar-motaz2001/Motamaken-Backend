<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Support\LikeSearch;
use App\Support\Paged;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminConversationController extends Controller
{
    /**
     * List conversations.
     *
     * Every conversation on the platform, latest activity first, with its members, project and last message. `q`
     * searches member names, usernames and emails.
     *
     * @return array{data: list<array<string, mixed>>, meta: array<string, int>}
     */
    public function index(Request $request): array
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100'], 'page' => ['nullable', 'integer', 'min:1']]);

        $page = Conversation::query()
            ->with(['participants', 'project', 'lastMessage.sender'])
            ->withCount('messages')
            ->when($request->input('q'), fn ($query, $term) => $query->whereHas(
                'participants', fn ($users) => LikeSearch::apply($users, ['users.first_name', 'users.last_name', 'users.username', 'users.email'], $term),
            ))
            ->orderByDesc('last_message_at')->orderByDesc('id')
            ->paginate(20);

        return [
            'data' => $page->getCollection()->map(fn (Conversation $conversation) => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'isGroup' => $conversation->isGroup(),
                'members' => $conversation->participants->map(fn ($user) => Paged::user($user))->values(),
                'project' => $conversation->project ? ['id' => $conversation->project->id, 'title' => $conversation->project->title] : null,
                'messagesCount' => $conversation->messages_count,
                'lastMessage' => $conversation->lastMessage ? [
                    'body' => $conversation->lastMessage->body,
                    'type' => $conversation->lastMessage->type,
                    'sender' => Paged::user($conversation->lastMessage->sender),
                ] : null,
                'lastMessageAt' => $conversation->last_message_at,
            ])->values()->all(),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }

    /**
     * Read a conversation.
     *
     * Its members and messages, oldest first, for moderation (read-only; reading here marks nothing as read).
     *
     * @return array{conversation: array<string, mixed>, data: AnonymousResourceCollection, meta: array<string, int>}
     */
    public function show(int $conversation): array
    {
        $conversation = Conversation::with(['participants', 'project'])->findOrFail($conversation);
        $page = $conversation->messages()->with('sender')->orderBy('id')->paginate(50);

        return [
            'conversation' => [
                'id' => $conversation->id,
                'title' => $conversation->title,
                'members' => $conversation->participants->map(fn ($user) => Paged::user($user))->values(),
                'project' => $conversation->project ? ['id' => $conversation->project->id, 'title' => $conversation->project->title] : null,
            ],
            'data' => MessageResource::collection($page->getCollection()),
            'meta' => ['current_page' => $page->currentPage(), 'last_page' => $page->lastPage(), 'total' => $page->total()],
        ];
    }
}
