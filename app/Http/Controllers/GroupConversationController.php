<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiException;
use App\Http\Requests\GroupRequest;
use App\Http\Resources\ConversationResource;
use App\Models\Conversation;
use App\Services\Chat\ConversationService;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

#[Group('Messages', 'The Messages page: conversations, chat messages, voice messages and attachments.', weight: 5)]
class GroupConversationController extends Controller
{
    public function __construct(private readonly ConversationService $conversations) {}

    /**
     * Create a group chat.
     *
     * A chat for several people (up to 20). You become its manager. Everything else works like a direct chat: the same
     * message, read and unread endpoints. The new members are notified.
     */
    public function store(GroupRequest $request): JsonResponse
    {
        $conversation = $this->conversations->createGroup(
            $request->user(), $request->input('title'), $request->input('userIds'), $request->input('projectId'),
        );

        return $this->respond($conversation->id, $request, 201);
    }

    /**
     * Add members.
     *
     * Group manager only. People already in the group are skipped; a new member does not see old messages as unread.
     */
    public function addMembers(GroupRequest $request, int $conversation): JsonResponse
    {
        $group = $this->managed($conversation, $request);

        if ($group->participants()->count() + count(array_diff($request->input('userIds'), $group->participants()->pluck('users.id')->all())) > GroupRequest::MAX_MEMBERS) {
            throw new ApiException(__('chat.group_too_big', ['max' => GroupRequest::MAX_MEMBERS]), 'group_too_big');
        }

        $this->conversations->addMembers($group, $request->user(), $request->input('userIds'));

        return $this->respond($group->id, $request);
    }

    /**
     * Remove a member.
     *
     * Group manager only. The manager cannot be removed; they leave instead.
     */
    public function removeMember(Request $request, int $conversation, int $user): JsonResponse
    {
        $group = $this->managed($conversation, $request);

        if ($user === $group->created_by) {
            throw new ApiException(__('chat.group_cannot_remove_creator'), 'cannot_remove_creator');
        }

        $this->conversations->removeMember($group, $user);

        return $this->respond($group->id, $request);
    }

    /**
     * Leave the group.
     *
     * If the manager leaves, the longest-standing member takes over.
     *
     * @return array{success: bool}
     */
    public function leave(Request $request, int $conversation): array
    {
        $group = Conversation::forUser($request->user())->where('type', Conversation::GROUP)->findOrFail($conversation);

        $this->conversations->removeMember($group, $request->user()->id);

        if ($group->created_by === $request->user()->id) {
            $group->update(['created_by' => $group->participants()->orderBy('conversation_user.user_id')->value('users.id')]);
        }

        return ['success' => true];
    }

    /**
     * Loads a group chat that the current user created; only the creator may change it.
     */
    private function managed(int $id, Request $request): Conversation
    {
        $group = Conversation::forUser($request->user())->where('type', Conversation::GROUP)->findOrFail($id);

        if ($group->created_by !== $request->user()->id) {
            throw new ApiException(__('chat.group_creator_only'), 'forbidden', 403);
        }

        return $group;
    }

    /**
     * Returns the group as a conversation resource with the given HTTP status.
     */
    private function respond(int $id, Request $request, int $status = 200): JsonResponse
    {
        $conversation = Conversation::forUser($request->user())->with(['participants', 'lastMessage', 'project'])->findOrFail($id);

        return (new ConversationResource($conversation))->response()->setStatusCode($status);
    }
}
