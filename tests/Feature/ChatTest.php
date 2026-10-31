<?php

namespace Tests\Feature;

use App\Models\Conversation;
use App\Models\Message;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ConversationStarted;
use App\Services\Chat\ConversationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ChatTest extends TestCase
{
    use RefreshDatabase;

    private User $me;

    private User $other;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->me = User::factory()->create(['first_name' => 'Abdul', 'last_name' => 'Rahman']);
        $this->other = User::factory()->create(['first_name' => 'Hosaam', 'last_name' => 'Amar']);
    }

    public function test_starting_a_conversation_is_idempotent_and_notifies_the_other_person(): void
    {
        Notification::fake();
        $project = Project::factory()->create();

        $first = $this->actingAs($this->me, 'sanctum')
            ->postJson('/api/conversations', ['userId' => $this->other->id, 'projectId' => $project->id, 'body' => 'Hello!'])
            ->assertCreated()
            ->assertJsonPath('data.participant.fullName', 'Hosaam Amar')
            ->assertJsonPath('data.project.id', $project->id)
            ->assertJsonPath('data.lastMessage.preview', 'Hello!')
            ->assertJsonPath('data.unreadCount', 0);

        // The other person starting it again lands in the same conversation.
        $this->actingAs($this->other, 'sanctum')
            ->postJson('/api/conversations', ['userId' => $this->me->id])
            ->assertOk()
            ->assertJsonPath('data.id', $first->json('data.id'))
            ->assertJsonPath('data.unreadCount', 1);

        Notification::assertSentToTimes($this->other, ConversationStarted::class, 1);
        $this->assertDatabaseCount('conversations', 1);
    }

    public function test_with_owners_first_on_freelancers_wait_for_the_owner_to_write(): void
    {
        Setting::put('chat.owners_first', true);
        $owner = User::factory()->create();
        Project::factory()->create(['user_id' => $owner->id]);
        $freelancer = User::factory()->create();
        Profile::factory()->create(['user_id' => $freelancer->id]);

        $this->actingAs($freelancer, 'sanctum')->postJson('/api/conversations', ['userId' => $owner->id])->assertForbidden()->assertJsonPath('code', 'owners_write_first');
        $this->app['auth']->forgetGuards();
        $this->actingAs($owner, 'sanctum')->postJson('/api/conversations', ['userId' => $freelancer->id])->assertCreated();
        $this->app['auth']->forgetGuards();
        $this->actingAs($freelancer, 'sanctum')->postJson('/api/conversations', ['userId' => $owner->id, 'body' => 'Thanks for writing'])->assertOk();
    }

    public function test_cannot_start_a_conversation_with_yourself_or_nobody(): void
    {
        $this->actingAs($this->me, 'sanctum')->postJson('/api/conversations', ['userId' => $this->me->id])
            ->assertStatus(422)->assertJsonPath('errors.userId.0', 'You cannot start a conversation with yourself.');
        $this->actingAs($this->me, 'sanctum')->postJson('/api/conversations', ['userId' => 9999])->assertStatus(422);

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/conversations', ['userId' => $this->other->id])->assertUnauthorized();
    }

    public function test_the_notification_shows_in_the_bell_and_can_be_marked_read(): void
    {
        $this->actingAs($this->me, 'sanctum')->postJson('/api/conversations', ['userId' => $this->other->id])->assertCreated();

        $this->actingAs($this->other, 'sanctum')->getJson('/api/me/summary')->assertJsonPath('unreadNotifications', 1);

        $notification = $this->actingAs($this->other, 'sanctum')->getJson('/api/notifications')->assertOk()
            ->assertJsonPath('data.0.type', 'conversation.started')
            ->assertJsonPath('data.0.message', 'Abdul Rahman started a conversation with you.')
            ->assertJsonPath('data.0.readAt', null)
            ->json('data.0');

        $this->actingAs($this->other, 'sanctum')->getJson('/api/notifications', ['Accept-Language' => 'ar'])
            ->assertJsonPath('data.0.message', 'بدأ Abdul Rahman محادثة معك.');

        // Somebody else cannot touch it.
        $this->actingAs($this->me, 'sanctum')->postJson("/api/notifications/{$notification['id']}/read")->assertNotFound();

        $this->actingAs($this->other, 'sanctum')->postJson("/api/notifications/{$notification['id']}/read")
            ->assertOk()->assertJsonPath('data.readAt', fn ($at) => $at !== null);
        $this->actingAs($this->other, 'sanctum')->getJson('/api/me/summary')->assertJsonPath('unreadNotifications', 0);

        $this->actingAs($this->me, 'sanctum')->postJson('/api/conversations', ['userId' => User::factory()->create()->id]);
        $this->actingAs($this->other, 'sanctum')->postJson('/api/notifications/read-all')->assertExactJson(['success' => true]);
    }

    public function test_text_voice_and_attachment_messages(): void
    {
        $conversation = $this->conversation();

        $this->as($this->me)->postJson("/api/conversations/{$conversation}/messages", ['body' => 'Hello 🙌🏻'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'text')
            ->assertJsonPath('data.isMine', true)
            ->assertJsonPath('data.status', 'sent')
            ->assertJsonMissingPath('data.attachment');

        $voice = $this->as($this->me)->post("/api/conversations/{$conversation}/messages", [
            'type' => 'voice', 'duration' => 130, 'file' => UploadedFile::fake()->create('rec.m4a', 40, 'audio/x-m4a'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'voice')
            ->assertJsonPath('data.duration', 130)
            ->assertJsonPath('data.attachment.name', 'rec.m4a');

        $pdf = $this->as($this->me)->post("/api/conversations/{$conversation}/messages", [
            'body' => 'the brief', 'file' => UploadedFile::fake()->create('brief.pdf', 100, 'application/pdf'),
        ], ['Accept' => 'application/json'])
            ->assertCreated()
            ->assertJsonPath('data.type', 'attachment', 'type is inferred from the file')
            ->assertJsonPath('data.body', 'the brief');

        Storage::disk('local')->assertExists(Message::find($voice->json('data.id'))->attachment_path);

        // The signed link works without a token, and only unmodified.
        $url = $pdf->json('data.attachment.url');
        $this->get($url)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=brief.pdf');
        $this->get($url.'x')->assertForbidden();
        $this->get(route('messages.attachment', $pdf->json('data.id')))->assertForbidden();

        $this->get($voice->json('data.attachment.url'))->assertOk()
            ->assertHeader('Content-Disposition', 'inline; filename=rec.m4a');
    }

    public function test_message_validation(): void
    {
        $conversation = $this->conversation();
        $send = fn (array $data) => $this->as($this->me)->post("/api/conversations/{$conversation}/messages", $data, ['Accept' => 'application/json']);

        $send([])->assertStatus(422)->assertJsonValidationErrors('body');
        $send(['body' => '   '])->assertStatus(422)->assertJsonValidationErrors('body');
        $send(['body' => str_repeat('a', 5001)])->assertStatus(422)->assertJsonValidationErrors('body');
        $send(['type' => 'voice', 'file' => UploadedFile::fake()->create('r.m4a', 10, 'audio/x-m4a')])
            ->assertStatus(422)->assertJsonValidationErrors('duration');
        $send(['type' => 'voice', 'duration' => 3])->assertStatus(422)->assertJsonValidationErrors('file');
        $send(['type' => 'attachment', 'body' => 'no file'])->assertStatus(422)->assertJsonValidationErrors('file');
        $send(['type' => 'text', 'body' => 'x', 'file' => UploadedFile::fake()->create('a.pdf', 10, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('type');
        // Anything that can run in a browser is refused, and so is a document sent as "voice".
        $send(['file' => UploadedFile::fake()->create('evil.html', 1, 'text/html')])->assertStatus(422)->assertJsonValidationErrors('file');
        $send(['file' => UploadedFile::fake()->create('evil.svg', 1, 'image/svg+xml')])->assertStatus(422)->assertJsonValidationErrors('file');
        $send(['type' => 'voice', 'duration' => 3, 'file' => UploadedFile::fake()->create('a.pdf', 1, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('file');
        $send(['file' => UploadedFile::fake()->create('big.pdf', config('marketplace.chat.max_file_kb') + 1, 'application/pdf')])
            ->assertStatus(422)->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('messages', 0);
    }

    public function test_conversation_list_shows_unread_counts_and_supports_search_and_sorting(): void
    {
        $chat = app(ConversationService::class);
        $people = collect(['Hosaam Amar', 'Mostafa Salem', 'Omar Khaled'])->map(function ($name) {
            [$first, $last] = explode(' ', $name);

            return User::factory()->create(['first_name' => $first, 'last_name' => $last]);
        });

        // Oldest -> newest: Hosaam (read), Mostafa (2 unread), Omar (1 unread).
        foreach ($people as $index => $person) {
            [$conversation] = $chat->findOrCreate($this->me, $person);
            $this->travelTo(now()->addMinutes(10));
            $chat->send($conversation, $person, ['type' => 'text', 'body' => "from {$person->first_name}"]);
            if ($index === 1) {
                $chat->send($conversation, $person, ['type' => 'text', 'body' => 'and again']);
            }
            if ($index === 0) {
                $chat->markRead($conversation, $this->me);
            }
        }

        $names = fn (string $query = '') => array_column(array_column(
            $this->as($this->me)->getJson("/api/conversations{$query}")->assertOk()->json('data'), 'participant',
        ), 'fullName');

        $this->assertSame(['Omar Khaled', 'Mostafa Salem', 'Hosaam Amar'], $names());
        $this->assertSame(['Hosaam Amar', 'Mostafa Salem', 'Omar Khaled'], $names('?sort=oldest'));
        $this->assertSame(['Omar Khaled', 'Mostafa Salem', 'Hosaam Amar'], $names('?sort=newest'));
        $this->assertSame(['Omar Khaled', 'Mostafa Salem', 'Hosaam Amar'], $names('?sort=unread'));
        $this->assertSame(['Mostafa Salem'], $names('?q=MOSt'));
        $this->assertSame([], $names('?q=nobody'));

        $unread = collect($this->as($this->me)->getJson('/api/conversations')->json('data'))
            ->mapWithKeys(fn ($c) => [$c['participant']['fullName'] => $c['unreadCount']]);
        $this->assertSame(['Omar Khaled' => 1, 'Mostafa Salem' => 2, 'Hosaam Amar' => 0], $unread->all());

        $this->as($this->me)->getJson('/api/me/summary')->assertJsonPath('unreadMessages', 3);
        $this->as($this->me)->getJson('/api/conversations?sort=wrong')->assertStatus(422);
    }

    public function test_unread_sort_puts_unread_conversations_first_even_when_older(): void
    {
        $chat = app(ConversationService::class);
        [$older] = $chat->findOrCreate($this->me, $this->other);
        $third = User::factory()->create(['first_name' => 'Newer']);
        [$newer] = $chat->findOrCreate($this->me, $third);

        $chat->send($older, $this->other, ['type' => 'text', 'body' => 'old but unread']);
        $this->travelTo(now()->addHour());
        $chat->send($newer, $this->me, ['type' => 'text', 'body' => 'new, mine']);

        $order = fn (string $sort) => array_column($this->as($this->me)->getJson("/api/conversations?sort={$sort}")->json('data'), 'id');
        $this->assertSame([$newer->id, $older->id], $order('newest'));
        $this->assertSame([$older->id, $newer->id], $order('unread'));
    }

    public function test_empty_inbox(): void
    {
        $this->as($this->me)->getJson('/api/conversations')->assertOk()->assertJsonPath('data', []);
    }

    public function test_reading_a_conversation_clears_badges_and_gives_the_sender_a_double_check(): void
    {
        $conversation = $this->conversation();
        $chat = app(ConversationService::class);
        $mine = $chat->send(Message::query()->first()?->conversation ?? Conversation::find($conversation), $this->me, ['type' => 'text', 'body' => 'hi']);
        $theirs = $chat->send(Conversation::find($conversation), $this->other, ['type' => 'text', 'body' => 'hello']);

        $status = fn (User $viewer, int $id) => collect($this->as($viewer)->getJson("/api/conversations/{$conversation}/messages")->json('data'))
            ->firstWhere('id', $id)['status'];

        $this->assertSame('sent', $status($this->me, $mine->id));
        $this->assertNull($status($this->me, $theirs->id), 'status only describes your own messages');

        // Reading as me marks the other person's message, not my own.
        $this->as($this->me)->postJson("/api/conversations/{$conversation}/read")->assertOk()->assertExactJson(['marked' => 1]);
        $this->as($this->me)->postJson("/api/conversations/{$conversation}/read")->assertExactJson(['marked' => 0]);
        $this->assertSame('sent', $status($this->me, $mine->id));
        $this->as($this->me)->getJson("/api/conversations/{$conversation}")->assertJsonPath('data.unreadCount', 0);

        $this->as($this->other)->postJson("/api/conversations/{$conversation}/read")->assertExactJson(['marked' => 1]);
        $this->assertSame('read', $status($this->me, $mine->id));
    }

    public function test_messages_page_backwards_and_poll_forwards(): void
    {
        $conversation = Conversation::find($this->conversation());
        $chat = app(ConversationService::class);
        $ids = collect(range(1, 7))->map(fn ($i) => $chat->send($conversation, $i % 2 ? $this->me : $this->other, ['type' => 'text', 'body' => "m{$i}"])->id);

        $get = fn (string $query) => $this->as($this->me)->getJson("/api/conversations/{$conversation->id}/messages?{$query}")->assertOk();

        $latest = $get('limit=3');
        $this->assertSame($ids->slice(4)->values()->all(), array_column($latest->json('data'), 'id'), 'latest 3, oldest first');
        $latest->assertJsonPath('meta.hasMore', true);

        $older = $get('limit=3&before='.$ids[4]);
        $this->assertSame($ids->slice(1, 3)->values()->all(), array_column($older->json('data'), 'id'));
        $older->assertJsonPath('meta.hasMore', true);

        $oldest = $get('limit=3&before='.$ids[1]);
        $this->assertSame([$ids[0]], array_column($oldest->json('data'), 'id'));
        $oldest->assertJsonPath('meta.hasMore', false);

        $this->assertSame([$ids[5], $ids[6]], array_column($get('after='.$ids[4])->json('data'), 'id'));
        $this->assertSame([], $get('after='.$ids[6])->json('data'));
        $this->as($this->me)->getJson("/api/conversations/{$conversation->id}/messages?limit=0")->assertStatus(422);
    }

    public function test_outsiders_cannot_see_or_use_a_conversation(): void
    {
        $conversation = $this->conversation();
        $stranger = User::factory()->create();

        foreach ([
            ['getJson', "/api/conversations/{$conversation}"],
            ['getJson', "/api/conversations/{$conversation}/messages"],
            ['postJson', "/api/conversations/{$conversation}/messages", ['body' => 'hi']],
            ['postJson', "/api/conversations/{$conversation}/read"],
        ] as $call) {
            [$method, $url] = $call;
            $this->as($stranger)->{$method}($url, $call[2] ?? [])->assertNotFound();
        }

        $this->as($stranger)->getJson('/api/conversations')->assertJsonPath('data', []);

        $this->app['auth']->forgetGuards();
        $this->getJson("/api/conversations/{$conversation}/messages")->assertUnauthorized();
        $this->assertDatabaseCount('messages', 0);
    }

    public function test_online_status_follows_last_seen(): void
    {
        $conversation = $this->conversation();
        $this->other->forceFill(['last_seen_at' => now()->subMinute()])->save();

        $this->as($this->me)->getJson("/api/conversations/{$conversation}")->assertJsonPath('data.participant.isOnline', true);

        $this->travel(5)->minutes();
        $this->as($this->me)->getJson("/api/conversations/{$conversation}")->assertJsonPath('data.participant.isOnline', false);

        // Using the API refreshes the viewer's own last-seen time.
        $this->assertNotNull($this->me->fresh()->last_seen_at);
        $this->assertNull($this->as($this->me)->getJson("/api/conversations/{$conversation}")->json('data.participant.email'), 'no private data of the other user');
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }

    /**
     * An empty conversation between $this->me and $this->other.
     */
    private function conversation(): int
    {
        return app(ConversationService::class)->findOrCreate($this->me, $this->other)[0]->id;
    }
}
