<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\User;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class GroupChatTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $ana;

    private User $ben;

    private User $outsider;

    protected function setUp(): void
    {
        parent::setUp();

        $this->owner = User::factory()->create(['first_name' => 'Olga', 'last_name' => 'Owner']);
        $this->ana = User::factory()->create(['first_name' => 'Ana', 'last_name' => 'A']);
        $this->ben = User::factory()->create(['first_name' => 'Ben', 'last_name' => 'B']);
        $this->outsider = User::factory()->create();
    }

    public function test_creating_a_group_notifies_the_members_and_starts_with_one_message(): void
    {
        $this->createGroup()
            ->assertCreated()
            ->assertJsonPath('data.type', 'group')
            ->assertJsonPath('data.title', 'Website team')
            ->assertJsonPath('data.participant', null)
            ->assertJsonPath('data.createdBy', $this->owner->id)
            ->assertJsonPath('data.lastMessage.preview', 'Group created.')
            ->assertJsonPath('data.unreadCount', 0);

        $names = array_column($this->as($this->owner)->getJson('/api/conversations')->json('data.0.participants'), 'fullName');
        $this->assertEqualsCanonicalizing(['Olga Owner', 'Ana A', 'Ben B'], $names);

        $this->as($this->ana)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Olga Owner added you to the group "Website team".');
        $this->as($this->ana)->getJson('/api/conversations')->assertJsonPath('data.0.unreadCount', 1);
        $this->as($this->outsider)->getJson('/api/conversations')->assertJsonPath('data', []);
    }

    public function test_group_validation(): void
    {
        $post = fn (array $override) => $this->as($this->owner)->postJson('/api/conversations/group', ['title' => 'Team', 'userIds' => [$this->ana->id], ...$override]);

        $this->as($this->owner)->postJson('/api/conversations/group', [])->assertJsonValidationErrors(['title', 'userIds']);
        $post(['userIds' => []])->assertJsonValidationErrors('userIds');
        $post(['userIds' => [$this->owner->id]])->assertJsonValidationErrors('userIds.0');
        $post(['userIds' => [$this->ana->id, $this->ana->id]])->assertJsonValidationErrors('userIds.0');
        $post(['userIds' => [9999]])->assertJsonValidationErrors('userIds.0');
        $post(['userIds' => User::factory()->count(20)->create()->pluck('id')->all()])->assertJsonValidationErrors('userIds');
        $post(['title' => 'x'])->assertJsonValidationErrors('title');
        $this->assertDatabaseCount('conversations', 0);
    }

    public function test_reading_moves_only_my_own_position(): void
    {
        $id = $this->createGroup()->json('data.id');
        $send = fn (User $who, string $body) => $this->as($who)->postJson("/api/conversations/{$id}/messages", ['body' => $body])->assertCreated();
        $send($this->ana, 'Hi all');
        $send($this->ben, 'Hello');

        $unread = fn (User $who) => $this->as($who)->getJson("/api/conversations/{$id}")->json('data.unreadCount');

        // Writing counts as having read everything before it: Ana wrote first (only Ben's message is new to her) and Ben wrote last.
        $this->assertSame(2, $unread($this->owner));
        $this->assertSame(1, $unread($this->ana));
        $this->assertSame(0, $unread($this->ben));

        $this->as($this->owner)->getJson('/api/me/summary')->assertJsonPath('unreadMessages', 2);
        $this->as($this->owner)->postJson("/api/conversations/{$id}/read")->assertOk()->assertExactJson(['marked' => 2]);
        $this->as($this->owner)->postJson("/api/conversations/{$id}/read")->assertExactJson(['marked' => 0]);

        $this->assertSame(0, $unread($this->owner));
        $this->assertSame(1, $unread($this->ana), 'Ana\'s count is her own');
        $this->as($this->owner)->getJson('/api/me/summary')->assertJsonPath('unreadMessages', 0);
        $this->as($this->ana)->getJson('/api/conversations?sort=unread')->assertJsonPath('data.0.id', $id);
    }

    public function test_the_sender_sees_how_many_members_read_a_message(): void
    {
        $id = $this->createGroup()->json('data.id');
        $messageId = $this->as($this->ana)->postJson("/api/conversations/{$id}/messages", ['body' => 'Status?'])->json('data.id');

        $mine = fn () => collect($this->as($this->ana)->getJson("/api/conversations/{$id}/messages")->json('data'))->firstWhere('id', $messageId);

        $this->assertSame(['sent', 0], [$mine()['status'], $mine()['seenBy']]);
        $this->assertSame('Ana A', $mine()['sender']['fullName']);

        $this->as($this->ben)->postJson("/api/conversations/{$id}/read")->assertOk();
        $this->assertSame(['sent', 1], [$mine()['status'], $mine()['seenBy']], 'one of two read it');

        $this->as($this->owner)->postJson("/api/conversations/{$id}/read")->assertOk();
        $this->assertSame(['read', 2], [$mine()['status'], $mine()['seenBy']], 'everyone read it');
    }

    public function test_the_manager_adds_and_removes_members(): void
    {
        $id = $this->createGroup()->json('data.id');
        $this->as($this->ana)->postJson("/api/conversations/{$id}/messages", ['body' => 'Before Zed joined'])->assertCreated();
        $zed = User::factory()->create(['first_name' => 'Zed']);

        $this->as($this->ana)->postJson("/api/conversations/{$id}/members", ['userIds' => [$zed->id]])->assertForbidden()->assertJsonPath('code', 'forbidden');
        $this->as($this->owner)->postJson("/api/conversations/{$id}/members", ['userIds' => [$zed->id, $this->ana->id]])
            ->assertOk()->assertJsonCount(4, 'data.participants');

        $this->as($zed)->getJson("/api/conversations/{$id}")->assertJsonPath('data.unreadCount', 0)->assertJsonPath('data.title', 'Website team');
        $this->as($zed)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'group.added');

        $this->as($this->ana)->deleteJson("/api/conversations/{$id}/members/{$zed->id}")->assertForbidden();
        $this->as($this->owner)->deleteJson("/api/conversations/{$id}/members/{$this->owner->id}")->assertStatus(422)->assertJsonPath('code', 'cannot_remove_creator');
        $this->as($this->owner)->deleteJson("/api/conversations/{$id}/members/{$zed->id}")->assertOk()->assertJsonCount(3, 'data.participants');
        $this->as($zed)->getJson("/api/conversations/{$id}")->assertNotFound();
        $this->as($zed)->postJson("/api/conversations/{$id}/messages", ['body' => 'Can I still talk?'])->assertNotFound();
    }

    public function test_a_group_is_limited_to_twenty_people(): void
    {
        $id = $this->createGroup(User::factory()->count(19)->create()->pluck('id')->all())->assertCreated()->json('data.id');

        $this->as($this->owner)->postJson("/api/conversations/{$id}/members", ['userIds' => [User::factory()->create()->id]])
            ->assertStatus(422)->assertJsonPath('code', 'group_too_big');
    }

    public function test_members_can_leave_and_the_manager_role_passes_on(): void
    {
        $id = $this->createGroup()->json('data.id');

        $this->as($this->ana)->postJson("/api/conversations/{$id}/leave")->assertOk()->assertExactJson(['success' => true]);
        $this->as($this->ana)->getJson("/api/conversations/{$id}")->assertNotFound();

        $this->as($this->owner)->postJson("/api/conversations/{$id}/leave")->assertOk();
        $this->assertSame($this->ben->id, Conversation::find($id)->created_by, 'the remaining member manages the group now');

        $direct = app(ConversationService::class)->findOrCreate($this->owner, $this->ben)[0];
        $this->as($this->owner)->postJson("/api/conversations/{$direct->id}/leave")->assertNotFound();
    }

    public function test_groups_and_direct_chats_live_side_by_side(): void
    {
        $this->createGroup();
        $this->as($this->owner)->postJson('/api/conversations', ['userId' => $this->ana->id, 'body' => 'Just us'])->assertCreated()
            ->assertJsonPath('data.type', 'direct')->assertJsonPath('data.participant.fullName', 'Ana A')->assertJsonPath('data.participants', null)
            ->assertJsonMissingPath('data.createdBy');

        $this->as($this->owner)->getJson('/api/conversations')->assertJsonPath('meta.total', 2);
        $this->as($this->owner)->getJson('/api/conversations?q=website')->assertJsonPath('meta.total', 1)->assertJsonPath('data.0.type', 'group');
        $this->as($this->owner)->getJson('/api/conversations?q=ana')->assertJsonPath('meta.total', 2)
            ->assertJsonPath('data.0.type', fn ($type) => in_array($type, ['direct', 'group'], true));

        // The direct chat keeps its own read receipts.
        $this->as($this->ana)->getJson('/api/me/summary')->assertJsonPath('unreadMessages', 2);
    }

    /**
     * @param  list<int>|null  $members
     */
    private function createGroup(?array $members = null): TestResponse
    {
        return $this->as($this->owner)->postJson('/api/conversations/group', [
            'title' => 'Website team', 'userIds' => $members ?? [$this->ana->id, $this->ben->id],
        ]);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
