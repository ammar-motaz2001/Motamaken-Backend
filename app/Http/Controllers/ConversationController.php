<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConversationIndexRequest;
use App\Http\Requests\StartConversationRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\User;
use App\Services\Chat\ConversationService;
use App\Support\LikeSearch;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

#[Group('Messages', 'The Messages page: conversations, chat messages, voice messages and attachments.', weight: 5)]
class ConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * List conversations.
     *
     * The left column of the Messages page. Empty when the user has no conversations yet
     * (show the "Your inbox is empty" screen).
     */
    public function index(ConversationIndexRequest $request): AnonymousResourceCollection
    {
        $me = $request->user();

        $conversations = Conversation::forUser($me)
            ->with(['participants', 'lastMessage', 'project'])
            ->when($request->input('q'), fn ($query, $term) => $query->where(fn ($query) => $query
                ->whereHas('participants', fn ($query) => LikeSearch::apply(
                    $query->where('users.id', '!=', $me->id),
                    ['users.first_name', 'users.last_name', 'users.username'],
                    $term,
                ))
                ->orWhere(fn ($query) => LikeSearch::apply($query, ['conversations.title'], $term))))
            ->when($request->input('sort', 'newest'), fn ($query, $sort) => match ($sort) {
                'oldest' => $query->orderBy('conversations.last_message_at'),
                'unread' => $query
                    ->orderByRaw('exists (select 1 from messages where messages.conversation_id = conversations.id and '.Message::UNREAD_SQL.') desc', [$me->id, $me->id])
                    ->orderByDesc('conversations.last_message_at'),
                default => $query->orderByDesc('conversations.last_message_at'),
            })
            ->orderByDesc('conversations.id')
            ->paginate($request->integer('perPage', 20));

        return ConversationResource::collection($conversations);
    }

    /**
     * Start a conversation.
     *
     * Opens the conversation with a user, or returns the existing one (a pair of users has exactly one).
     * Use it behind "Hire Me" and "Add Your Offer"; pass `projectId` when it starts from an ad.
     * `body` sends the first message in the same call. Returns 201 when created, 200 when it already existed.
     */
    public function store(StartConversationRequest $request): JsonResponse
    {
        $me = $request->user();

        [$conversation, $created] = $this->conversations->findOrCreate(
            $me, User::findOrFail($request->integer('userId')), $request->input('projectId'),
        );

        if ($request->filled('body')) {
            $this->conversations->send($conversation, $me, ['type' => Message::TYPE_TEXT, 'body' => $request->input('body')]);
        }

        $resource = $this->resource($conversation->id, $me);

        return $created ? $resource->response()->setStatusCode(201) : $resource->response();
    }

    /**
     * Show a conversation.
     */
    public function show(Request $request, int $conversation): ConversationResource
    {
        return $this->resource($conversation, $request->user());
    }

    /**
     * Mark as read.
     *
     * Call it when the user opens the conversation. Clears the unread badge and turns the sender's single
     * check mark into a double one.
     *
     * @return array{marked: int}
     */
    public function read(Request $request, int $conversation): array
    {
        $me = $request->user();
        $conversation = Conversation::forUser($me)->findOrFail($conversation);

        return ['marked' => $this->conversations->markRead($conversation, $me)];
    }

    private function resource(int $id, User $me): ConversationResource
    {
        return new ConversationResource(
            Conversation::forUser($me)->with(['participants', 'lastMessage', 'project'])->findOrFail($id),
        );
    }
}
