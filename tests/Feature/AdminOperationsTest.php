<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\AdminLog;
use App\Models\Broadcast;
use App\Models\Conversation;
use App\Models\Dispute;
use App\Models\MailLog;
use App\Models\Message;
use App\Models\Milestone;
use App\Models\Project;
use App\Models\Report;
use App\Models\User;
use App\Notifications\ActivityEmail;
use App\Notifications\ActivityNotification;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\Concerns\HandlesMoney;
use Tests\TestCase;

class AdminOperationsTest extends TestCase
{
    use HandlesMoney, RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_a_settled_dispute_can_be_appealed_once_and_overturned_with_a_correction(): void
    {
        [$client, $freelancer, $dispute] = $this->settledDispute();

        $this->as($client)->getJson("/api/disputes/{$dispute}")->assertJsonPath('data.permissions.canAppeal', true);
        $this->as(User::factory()->create())->post("/api/disputes/{$dispute}/appeal", ['reason' => 'I was not part of this but I want to appeal.'], ['Accept' => 'application/json'])->assertForbidden();

        $this->as($client)->post("/api/disputes/{$dispute}/appeal", [
            'reason' => 'The admin missed the chat where the freelancer admitted the files were missing.',
            'file' => UploadedFile::fake()->create('chat.pdf', 30, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertCreated();
        $this->as($client)->post("/api/disputes/{$dispute}/appeal", ['reason' => 'Trying a second time to appeal this decision.'], ['Accept' => 'application/json'])
            ->assertJsonPath('code', 'cannot_appeal');
        $this->as($client)->getJson("/api/disputes/{$dispute}")->assertJsonPath('data.appeals.0.status', 'pending')->assertJsonPath('data.permissions.canAppeal', false);

        $appeal = $this->as($this->admin)->getJson('/api/admin/appeals')->assertOk()->assertJsonPath('data.0.dispute.resolution', 'freelancer_payout')->json('data.0.id');
        $before = $this->balance($freelancer);

        $this->as($this->admin)->postJson("/api/admin/appeals/{$appeal}/decide", ['decision' => 'overturned', 'note' => 'Half of the work was missing.', 'amount' => 300, 'to' => 'client'])
            ->assertOk()->assertJsonPath('data.status', 'overturned')->assertJsonPath('data.adjustment', 300);
        $this->assertSame(300.0, $this->balance($client));
        $this->assertSame($before - 300, $this->balance($freelancer));
        $this->assertBooksBalance();
        $this->as($this->admin)->postJson("/api/admin/appeals/{$appeal}/decide", ['decision' => 'upheld', 'note' => 'Changed my mind.'])->assertJsonPath('code', 'appeal_decided');

        $this->as($freelancer)->getJson("/api/disputes/{$dispute}")->assertJsonPath('data.appeals.0.adjustmentTo', 'client');
        $this->travel(10)->days();
        $this->as($freelancer)->getJson("/api/disputes/{$dispute}")->assertJsonPath('data.permissions.canAppeal', false);
    }

    public function test_support_tickets_go_back_and_forth(): void
    {
        $user = User::factory()->create(['first_name' => 'Nada']);

        $ticket = $this->as($user)->postJson('/api/support', ['subject' => 'Cannot withdraw', 'category' => 'payments', 'body' => 'The withdraw button stays grey for me.'])
            ->assertCreated()->assertJsonPath('data.status', 'open')->json('data.id');

        $this->as($this->admin)->getJson('/api/admin/support')->assertJsonPath('data.0.user.fullName', $user->full_name)->assertJsonPath('counts.open', 1);
        $this->as($this->admin)->post("/api/admin/support/{$ticket}/reply", [
            'body' => 'Verify your identity first, then it turns green.', 'file' => UploadedFile::fake()->create('steps.pdf', 10, 'application/pdf'),
        ], ['Accept' => 'application/json'])->assertOk()->assertJsonPath('data.status', 'answered');
        $this->assertDatabaseHas('support_tickets', ['id' => $ticket, 'assigned_to' => $this->admin->id]);

        $thread = $this->as($user)->getJson("/api/support/{$ticket}")->assertOk()
            ->assertJsonPath('data.messages.1.isStaff', true)
            ->assertJsonPath('data.messages.1.author', null) // staff names stay private
            ->json('data.messages.1.attachment.url');
        $this->get($thread)->assertOk();
        $this->as($user)->getJson('/api/notifications')->assertJsonPath('data.0.type', 'support.replied');

        $this->as($user)->postJson("/api/support/{$ticket}/reply", ['body' => 'Done, thank you!'])->assertOk()->assertJsonPath('data.status', 'open');
        $this->as($user)->postJson("/api/support/{$ticket}/close")->assertJsonPath('data.status', 'closed');
        $this->as($user)->postJson("/api/support/{$ticket}/reply", ['body' => 'One more thing'])->assertJsonPath('code', 'ticket_closed');
        $this->as(User::factory()->create())->getJson("/api/support/{$ticket}")->assertNotFound();
    }

    public function test_reported_messages_are_the_only_ones_the_staff_read(): void
    {
        [$alice, $bob] = User::factory()->count(2)->create();
        $conversation = Conversation::create(['pair_key' => min($alice->id, $bob->id).':'.max($alice->id, $bob->id)]);
        $conversation->participants()->attach([$alice->id, $bob->id]);
        $message = Message::create(['conversation_id' => $conversation->id, 'sender_id' => $bob->id, 'type' => 'text', 'body' => 'Pay me on PayPal and skip the site fee.']);

        $this->as(User::factory()->create())->postJson('/api/reports', ['targetType' => 'message', 'targetId' => $message->id, 'reason' => 'scam'])->assertNotFound();
        $report = $this->as($alice)->postJson('/api/reports', ['targetType' => 'message', 'targetId' => $message->id, 'reason' => 'scam'])->assertCreated()->json('data.id');

        $this->as($this->admin)->getJson('/api/admin/reports')->assertOk()
            ->assertJsonPath('data.0.target.title', 'Pay me on PayPal and skip the site fee.')
            ->assertJsonPath('data.0.priority', 'high');
        $this->as($this->admin)->postJson("/api/admin/reports/{$report}/resolve", ['decision' => 'actioned', 'action' => 'removed', 'note' => 'Asking for payment outside the platform.'])
            ->assertOk()->assertJsonPath('data.actionTaken', 'removed');
        $this->assertNull($message->fresh()->body);
        $this->as($alice)->getJson("/api/conversations/{$conversation->id}/messages")->assertJsonPath('data.0.removed', true);

        $this->as($this->admin)->getJson('/api/admin/messages/stats')->assertOk()
            ->assertJsonPath('data.conversations.total', 1)
            ->assertJsonPath('data.messages.removed', 1)
            ->assertJsonPath('data.reports.total', 1)
            ->assertJsonCount(24, 'data.byHour');
    }

    public function test_a_report_can_suspend_the_person_responsible(): void
    {
        $spammer = User::factory()->create();
        $project = Project::factory()->create(['user_id' => $spammer->id]);
        $report = $this->as(User::factory()->create())->postJson('/api/reports', ['targetType' => 'project', 'targetId' => $project->id, 'reason' => 'spam'])->json('data.id');

        $this->as($this->admin)->postJson("/api/admin/reports/{$report}/priority", ['priority' => 'low'])->assertJsonPath('data.priority', 'low');
        $this->as($this->admin)->postJson("/api/admin/reports/{$report}/resolve", ['decision' => 'actioned', 'action' => 'suspended'])->assertJsonValidationErrors('note');
        $this->as($this->admin)->postJson("/api/admin/reports/{$report}/resolve", ['decision' => 'actioned', 'action' => 'suspended', 'note' => 'Repeated spam'])->assertOk();
        $this->assertTrue($spammer->fresh()->isSuspended());
    }

    public function test_announcements_reach_the_chosen_audience(): void
    {
        Notification::fake();
        $freelancer = User::factory()->create();
        $freelancer->profile()->create(['type' => 'freelancer']);
        User::factory()->count(2)->create();
        User::factory()->create(['suspended_at' => now()]);

        $this->as($this->admin)->postJson('/api/admin/broadcasts', $this->announcement(['audience' => 'freelancers', 'preview' => true]))->assertJsonPath('data.recipients', 1);
        $this->as($this->admin)->postJson('/api/admin/broadcasts', $this->announcement(['audience' => 'all', 'channels' => ['email']]))->assertCreated();

        $this->assertSame(4, Broadcast::first()->recipients); // the admin, the freelancer and two clients
        Notification::assertSentTo($freelancer, ActivityEmail::class);
        $this->as($this->admin)->getJson('/api/admin/broadcasts')->assertJsonPath('data.0.recipients', 4);
    }

    public function test_announcements_show_in_the_bell_in_the_readers_language(): void
    {
        $user = User::factory()->create();
        $this->as($this->admin)->postJson('/api/admin/broadcasts', $this->announcement(['audience' => 'user', 'userId' => $user->id]))->assertCreated();

        $this->as($user)->getJson('/api/notifications', ['Accept-Language' => 'ar'])
            ->assertJsonPath('data.0.title', 'تحديث جديد')
            ->assertJsonPath('data.0.message', 'أضفنا الدفع عبر مدى.');
    }

    public function test_notification_wording_can_be_changed(): void
    {
        $user = User::factory()->create();

        $template = collect($this->as($this->admin)->getJson('/api/admin/notification-templates')->assertOk()->json('data'))->firstWhere('type', 'kyc.approved');
        $this->assertSame([], $template['placeholders']);

        $this->as($this->admin)->putJson('/api/admin/notification-templates/kyc.approved', [
            'textEn' => 'Great news: you are verified!', 'textAr' => 'تم التوثيق!', 'subjectEn' => 'You are verified',
        ])->assertOk();
        $user->notify(new ActivityNotification('kyc.approved', null, []));
        $this->as($user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Great news: you are verified!');

        $this->as($this->admin)->deleteJson('/api/admin/notification-templates/kyc.approved')->assertOk();
        $this->as($user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'Your identity was verified. You can now withdraw money.');
        $this->as($this->admin)->putJson('/api/admin/notification-templates/not.a.type', ['textEn' => 'x', 'textAr' => 'x'])->assertNotFound();
    }

    public function test_email_settings_logs_and_integration_status(): void
    {
        $this->as($this->admin)->putJson('/api/admin/mail', ['enabled' => true, 'host' => 'smtp.example.com', 'port' => 587, 'username' => 'mailer', 'mailPassword' => 's3cret', 'fromAddress' => 'no-reply@mutamakin.com'])
            ->assertOk()->assertJsonPath('data.host', 'smtp.example.com')->assertJsonPath('data.hasPassword', true)->assertJsonMissingPath('data.password');
        $this->assertSame('s3cret', config('mail.mailers.smtp.password'));
        $this->assertStringNotContainsString('s3cret', json_encode(AdminLog::latest('id')->first()->changes));

        config(['mail.default' => 'array']);
        $this->as($this->admin)->postJson('/api/admin/mail/test', ['to' => 'ops@example.com'])->assertJsonPath('success', true);
        $this->assertSame('sent', MailLog::latest('id')->value('status'));
        $this->as($this->admin)->getJson('/api/admin/mail/logs')->assertJsonPath('data.0.to', 'ops@example.com');

        $this->as($this->admin)->putJson('/api/admin/mail', ['enabled' => false])->assertOk()->assertJsonPath('data.overridden', false);

        $services = collect($this->as($this->admin)->getJson('/api/admin/integrations')->assertOk()->json('data'))->keyBy('service');
        $this->assertSame(['payments', 'mail', 'recaptcha', 'queue', 'storage', 'ai'], $services->keys()->all());
        $this->assertSame('off', $services['ai']['status']);
        $this->as($this->admin)->getJson('/api/admin/integrations/logs')->assertOk();
        $this->as($this->admin)->getJson('/api/admin/integrations/failed-jobs')->assertOk();
    }

    public function test_identity_checks_can_ask_for_an_update_and_keep_a_history(): void
    {
        $user = User::factory()->create();
        $documents = fn () => ['documentType' => 'national_id', 'documentNumber' => '1234567890', 'fullName' => 'Kay Yee', 'dateOfBirth' => '1990-05-17',
            'front' => UploadedFile::fake()->create('front.jpg', 20, 'image/jpeg'), 'selfie' => UploadedFile::fake()->create('selfie.png', 20, 'image/png')];

        $first = $this->as($user)->post('/api/me/kyc', $documents(), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/admin/kyc/{$first}/request-update", ['reason' => 'The photo is blurry'])->assertOk()->assertJsonPath('data.status', 'needs_update');
        $this->assertSame('needs_update', $user->fresh()->kyc_status);

        $second = $this->as($user->fresh())->post('/api/me/kyc', $documents(), ['Accept' => 'application/json'])->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/admin/kyc/{$second}/approve")->assertOk();

        $this->as($this->admin)->getJson("/api/admin/kyc/{$second}")->assertOk()
            ->assertJsonPath('data.history.*.status', ['submitted', 'needs_update', 'submitted', 'approved'])
            ->assertJsonPath('data.history.1.note', 'The photo is blurry');
        $this->as($this->admin)->getJson('/api/admin/kyc?status=needs_update')->assertJsonCount(1, 'data');
    }

    public function test_ai_screening_files_a_report_without_slowing_the_user(): void
    {
        config(['ai.enabled' => true]);
        Http::fake(['*/api/generate' => Http::sequence()
            ->push(['response' => json_encode(['flagged' => true, 'reason' => 'scam', 'confidence' => 0.92, 'explanation' => 'Asks for payment outside the platform.'])])
            ->push('down', 500)]);

        $project = Project::factory()->create(['description' => 'Send the deposit to my bank account directly and I will start.']);

        $report = Report::where('target_type', 'project')->where('target_id', $project->id)->first();
        $this->assertSame(['ai', 'scam', 'high'], [$report->source, $report->reason, $report->priority]);
        $this->assertNull($report->reporter_id);
        $this->as($this->admin)->getJson('/api/admin/reports?source=ai')->assertJsonCount(1, 'data');

        // A broken AI server never breaks posting.
        $this->assertNotNull(Project::factory()->create());
        $this->assertDatabaseHas('integration_logs', ['service' => 'ai']);
    }

    /**
     * @return array{0: User, 1: User, 2: int}
     */
    private function settledDispute(): array
    {
        $client = User::factory()->create();
        $freelancer = User::factory()->create();
        $project = $this->hiredProject($client, $freelancer, 1000);
        $this->fund($client, 1000);
        $this->as($client)->postJson("/api/projects/{$project->id}/escrow", ['milestones' => [['title' => 'Everything', 'amount' => 1000]]])->assertCreated();
        $milestone = Milestone::firstOrFail();
        $this->as($client)->postJson("/api/milestones/{$milestone->id}/fund")->assertOk();
        $this->as($freelancer)->postJson("/api/milestones/{$milestone->id}/submit", ['note' => 'All the pages are done.'])->assertOk();
        $dispute = $this->as($client)->postJson("/api/milestones/{$milestone->id}/dispute", ['reason' => 'quality', 'description' => 'Half of the pages are missing from the delivery.'])
            ->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson("/api/admin/disputes/{$dispute}/resolve", ['resolution' => 'freelancer_payout', 'note' => 'Delivered as agreed.'])->assertOk();
        $this->assertSame(Dispute::RESOLVED, Dispute::find($dispute)->status);
        $this->assertSame(0.0, $this->balance(Account::ESCROW));

        return [$client, $freelancer, $dispute];
    }

    /**
     * @param  array<string, mixed>  $override
     * @return array<string, mixed>
     */
    private function announcement(array $override = []): array
    {
        return ['titleEn' => 'New update', 'titleAr' => 'تحديث جديد', 'bodyEn' => 'We added mada payments.', 'bodyAr' => 'أضفنا الدفع عبر مدى.', ...$override];
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
