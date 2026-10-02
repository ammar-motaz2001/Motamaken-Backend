<?php

namespace App\Http\Controllers;

use App\Http\Requests\SendMessageRequest;
use App\Http\Resources\MessageResource;
use App\Models\Conversation;
use App\Models\Message;
use App\Services\Chat\ConversationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

#[Group('Messages', 'The Messages page: conversations, chat messages, voice messages and attachments.', weight: 5)]
class MessageController extends Controller
{
    private const INLINE_MIME_TYPES = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];

    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * List messages.
     *
     * The chat window, oldest first. Returns the latest `limit` messages; pass `before` (a message id) to load
     * older ones when scrolling up. To poll for new messages, pass `after` (the last id you have): you get only newer ones.
     */
    public function index(Request $request, int $conversation): AnonymousResourceCollection
    {
        $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
            /** Load messages older than this message id. */
            'before' => ['nullable', 'integer'],
            /** Load messages newer than this message id (polling). */
            'after' => ['nullable', 'integer'],
        ]);

        $conversation = Conversation::forUser($request->user())->findOrFail($conversation);
        $limit = $request->integer('limit', 30);

        if ($request->filled('after')) {
            $messages = $conversation->messages()->where('id', '>', $request->integer('after'))->orderBy('id')->limit($limit)->get();
            $hasMore = false;
        } else {
            $page = $conversation->messages()
                ->when($request->filled('before'), fn ($query) => $query->where('id', '<', $request->integer('before')))
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $hasMore = $page->count() > $limit;
            $messages = $page->take($limit)->reverse()->values();
        }

        $messages->load('sender');
        $this->withGroupReaders($conversation, $messages);

        return MessageResource::collection($messages)->additional([
            /** True when older messages exist (show "load more" above the first message). */
            'meta' => ['hasMore' => $hasMore],
        ]);
    }

    /**
     * Send a message.
     *
     * Text goes as JSON. Voice messages and attachments go as `multipart/form-data` with a `file`:
     * images, documents and archives up to 20 MB, or an audio recording with its `duration` in seconds.
     */
    public function store(SendMessageRequest $request, int $conversation): JsonResponse
    {
        $me = $request->user();
        $conversation = Conversation::forUser($me)->findOrFail($conversation);

        $message = $this->conversations->send($conversation, $me, [
            'type' => $request->messageType(),
            'body' => $request->input('body'),
            'duration' => $request->input('duration'),
        ], $request->file('file'));

        return (new MessageResource($message))->response()->setStatusCode(201);
    }

    /**
     * Download an attachment.
     *
     * Reached through the signed `attachment.url` of a message (no token header needed, so it works in `<img>`
     * and `<audio>` tags). Links expire after an hour; list the messages again for fresh ones.
     *
     * @unauthenticated
     */
    public function attachment(int $message): StreamedResponse
    {
        $message = Message::whereNotNull('attachment_path')->findOrFail($message);
        $inline = in_array($message->attachment_mime, self::INLINE_MIME_TYPES, true)
            || ($message->type === Message::TYPE_VOICE && str_starts_with((string) $message->attachment_mime, 'audio/'));

        return Storage::disk('local')->response(
            $message->attachment_path,
            $message->attachment_name,
            ['X-Content-Type-Options' => 'nosniff', 'Cache-Control' => 'private, max-age=600'],
            $inline ? 'inline' : 'attachment',
        );
    }

    /**
     * In a group, work out how many of the other members have read each message.
     *
     * @param  Collection<int, Message>  $messages
     */
    private function withGroupReaders(Conversation $conversation, $messages): void
    {
        if (! $conversation->isGroup()) {
            return;
        }

        $positions = DB::table('conversation_user')->where('conversation_id', $conversation->id)->pluck('last_read_message_id', 'user_id');

        foreach ($messages as $message) {
            $others = $positions->except($message->sender_id);
            $message->others_count = $others->count();
            $message->seen_by = $others->filter(fn ($read) => (int) $read >= $message->id)->count();
        }
    }
}
