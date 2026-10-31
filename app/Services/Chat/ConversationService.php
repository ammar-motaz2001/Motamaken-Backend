<?php

namespace App\Services\Chat;

use App\Exceptions\ApiException;
use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ActivityNotification;
use App\Notifications\ConversationStarted;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ConversationService
{
    /**
     * The direct conversation between two users, created on first contact.
     *
     * @return array{0: Conversation, 1: bool} The conversation and whether it was just created.
     */
    public function findOrCreate(User $starter, User $other, ?int $projectId = null): array
    {
        $pairKey = Conversation::pairKey($starter->id, $other->id);

        if ($existing = Conversation::firstWhere('pair_key', $pairKey)) {
            return [$existing, false];
        }

        // With "owners write first" on, a freelancer or influencer cannot open a conversation with someone who posts
        // projects; once the owner writes, the conversation exists and both sides talk freely.
        if (Setting::get('chat.owners_first')
            && Profile::where('user_id', $starter->id)->exists()
            && Project::where('user_id', $other->id)->exists()
            && ! Project::where('user_id', $starter->id)->exists()) {
            throw new ApiException(__('chat.owners_first'), 'owners_write_first', 403);
        }

        try {
            $conversation = DB::transaction(function () use ($pairKey, $projectId, $starter, $other) {
                $conversation = Conversation::create(['pair_key' => $pairKey, 'project_id' => $projectId]);
                $conversation->participants()->attach([$starter->id, $other->id]);

                return $conversation;
            });
        } catch (UniqueConstraintViolationException) {
            // Both users opened the conversation at the same moment; the other request won.
            return [Conversation::where('pair_key', $pairKey)->firstOrFail(), false];
        }

        $other->notify(new ConversationStarted($starter, $conversation));

        return [$conversation, true];
    }

    /**
     * Post a message (text, voice or attachment) and make it the conversation's latest. In a group the sender's read
     * marker moves to it.
     *
     * @param  array{type: string, body?: ?string, duration?: ?int}  $content
     */
    public function send(Conversation $conversation, User $sender, array $content, ?UploadedFile $file = null): Message
    {
        $attributes = [
            'sender_id' => $sender->id,
            'type' => $content['type'],
            'body' => $content['body'] ?? null,
            'duration' => $content['duration'] ?? null,
        ];

        if ($file) {
            $attributes += [
                'attachment_path' => $file->store("messages/{$conversation->id}", 'local'),
                'attachment_name' => $file->getClientOriginalName(),
                'attachment_mime' => $file->getMimeType(),
                'attachment_size' => $file->getSize(),
            ];
        }

        return DB::transaction(function () use ($conversation, $attributes) {
            $message = $conversation->messages()->create($attributes);

            $conversation->update(['last_message_id' => $message->id, 'last_message_at' => $message->created_at]);

            // In a group, what you wrote counts as read by you.
            if ($conversation->isGroup()) {
                DB::table('conversation_user')->where('conversation_id', $conversation->id)->where('user_id', $attributes['sender_id'])
                    ->update(['last_read_message_id' => $message->id]);
            }

            return $message;
        });
    }

    /**
     * Mark everything the others sent as read. In a direct chat that is the double check mark on their side; in a group
     * it moves the user's own read position.
     *
     * @return int Number of messages that were unread.
     */
    public function markRead(Conversation $conversation, User $reader): int
    {
        if ($conversation->isGroup()) {
            $pivot = DB::table('conversation_user')->where('conversation_id', $conversation->id)->where('user_id', $reader->id);
            $from = (int) $pivot->value('last_read_message_id');

            $unread = $conversation->messages()->where('sender_id', '!=', $reader->id)->where('id', '>', $from)->count();
            $pivot->update(['last_read_message_id' => $conversation->messages()->max('id')]);

            return $unread;
        }

        return $conversation->messages()
            ->where('sender_id', '!=', $reader->id)
            ->whereNull('read_at')
            ->update(['read_at' => now()]);
    }

    /**
     * A chat for several people: a title and at least one other member.
     *
     * @param  list<int>  $memberIds
     */
    public function createGroup(User $creator, string $title, array $memberIds, ?int $projectId = null): Conversation
    {
        $members = collect($memberIds)->push($creator->id)->unique()->values();

        $conversation = DB::transaction(function () use ($creator, $title, $members, $projectId) {
            $conversation = Conversation::create([
                'type' => Conversation::GROUP, 'title' => $title, 'created_by' => $creator->id, 'project_id' => $projectId,
            ]);
            $conversation->participants()->attach($members->all());
            $this->announce($conversation, $creator, __('chat.group_created'));

            return $conversation;
        });

        $this->tellAdded($conversation, $creator, $members->reject(fn (int $id) => $id === $creator->id));

        return $conversation;
    }

    /**
     * The group chat of a team project: the client and everyone being paid. Made once, kept up to date on later calls.
     *
     * @param  list<int>  $memberIds
     */
    public function ensureProjectGroup(int $projectId, User $client, string $title, array $memberIds): Conversation
    {
        $existing = Conversation::where('type', Conversation::GROUP)->where('project_id', $projectId)->first();

        if (! $existing) {
            return $this->createGroup($client, $title, $memberIds, $projectId);
        }

        $existing->participants()->syncWithoutDetaching(collect($memberIds)->push($client->id)->all());

        return $existing;
    }

    /**
     * Add users to a group. They start with everything already read and are told they were added.
     *
     * @param  list<int>  $userIds
     */
    public function addMembers(Conversation $conversation, User $by, array $userIds): void
    {
        $new = collect($userIds)->diff($conversation->participants()->pluck('users.id'));
        // Someone joining does not start with a pile of unread messages.
        $latest = $conversation->messages()->max('id');

        foreach ($new as $id) {
            $conversation->participants()->attach($id, ['last_read_message_id' => $latest]);
        }

        $this->tellAdded($conversation, $by, $new);
    }

    /**
     * Take a user out of a group conversation.
     */
    public function removeMember(Conversation $conversation, int $userId): void
    {
        $conversation->participants()->detach($userId);
    }

    /**
     * Notify each new member that they were added to the group.
     */
    private function tellAdded(Conversation $conversation, User $by, Collection $userIds): void
    {
        foreach (User::whereIn('id', $userIds)->get() as $user) {
            $user->notify(new ActivityNotification('group.added', $by, [
                'conversationId' => $conversation->id, 'group' => $conversation->title,
            ]));
        }
    }

    /**
     * A line from the system at the start of a group, so a new chat is not empty.
     */
    private function announce(Conversation $conversation, User $creator, string $text): void
    {
        $message = $conversation->messages()->create(['sender_id' => $creator->id, 'type' => Message::TYPE_TEXT, 'body' => $text]);
        $conversation->update(['last_message_id' => $message->id, 'last_message_at' => $message->created_at]);
    }
}
