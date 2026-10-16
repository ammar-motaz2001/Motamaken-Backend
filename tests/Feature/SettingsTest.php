<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\ActivityEmail;
use App\Notifications\ActivityNotification;
use App\Notifications\OtpCode;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SettingsTest extends TestCase
{
    use RefreshDatabase;

    private const PASSWORD = 'Secret#123';

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->user = User::factory()->create([
            'email' => 'sara@example.com', 'email_verified_at' => now(), 'phone' => '+966501234567', 'password' => Hash::make(self::PASSWORD),
        ]);
    }

    public function test_the_profile_settings_update_names_and_username(): void
    {
        User::factory()->create(['username' => 'taken.name']);

        $this->as($this->user)->patchJson('/api/me', ['firstName' => 'Sara', 'lastName' => 'Ali', 'username' => 'sara.ali', 'subscribed' => false, 'email' => 'x@y.z'])
            ->assertOk()->assertJsonPath('data.fullName', 'Sara Ali')->assertJsonPath('data.username', 'sara.ali')
            ->assertJsonPath('data.subscribed', false)->assertJsonPath('data.email', 'sara@example.com');

        $this->as($this->user)->patchJson('/api/me', ['username' => 'taken.name', 'firstName' => 'S4ra'])->assertJsonValidationErrors(['username', 'firstName']);
    }

    public function test_changing_the_password_logs_out_other_devices(): void
    {
        $other = $this->user->createToken('web')->plainTextToken;
        $current = $this->user->createToken('web')->plainTextToken;
        $post = fn (array $body) => $this->withToken($current)->postJson('/api/me/password', $body);

        $post(['currentPassword' => 'nope', 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])->assertJsonValidationErrors('currentPassword');
        $post(['currentPassword' => self::PASSWORD, 'password' => 'weak', 'password_confirmation' => 'weak'])->assertJsonValidationErrors('password');
        $post(['currentPassword' => self::PASSWORD, 'password' => 'New#Pass123', 'password_confirmation' => 'Other#Pass123'])->assertJsonValidationErrors('password');
        $post(['currentPassword' => self::PASSWORD, 'password' => 'New#Pass123', 'password_confirmation' => 'New#Pass123'])->assertOk();

        $this->assertTrue(Hash::check('New#Pass123', $this->user->fresh()->password));
        $this->assertSame(1, $this->user->tokens()->count());
        $this->app['auth']->forgetGuards();
        $this->withToken($other)->getJson('/api/me/summary')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($current)->getJson('/api/me/summary')->assertOk();
    }

    public function test_notification_groups_decide_what_is_emailed(): void
    {
        Notification::fake();
        $this->as($this->user)->getJson('/api/me/notification-settings')
            ->assertJsonPath('data.messages', false)->assertJsonPath('data.proposals', true)->assertJsonPath('required', ['account']);
        $this->as($this->user)->patchJson('/api/me/notification-settings', ['messages' => true, 'proposals' => false, 'account' => false])
            ->assertOk()->assertJsonPath('data.messages', true)->assertJsonPath('data.proposals', false)->assertJsonPath('data.account', true);

        $this->assertTrue($this->user->fresh()->wantsEmailFor('messages'));
        $this->assertFalse($this->user->fresh()->wantsEmailFor('proposals'));
        $this->assertSame('payments', (new ActivityNotification('withdrawal.paid', null))->group());
        $this->assertNull((new ActivityNotification('something.else', null))->group());
    }

    public function test_a_bell_notification_is_also_emailed_when_its_group_is_on(): void
    {
        $user = $this->user->fresh();
        $user->notify(new ActivityNotification('proposal.declined', null, ['project' => 'Logo', 'projectId' => 5]));
        $user->notify(new ActivityNotification('conversation.started', null, ['conversationId' => 3]));

        $mails = collect(app('mailer')->getSymfonyTransport()->messages())->map(fn ($sent) => $sent->getOriginalMessage());
        $this->assertCount(1, $mails, 'messages are not emailed by default; proposals are, exactly once');
        $this->assertSame('New activity on Mutamakin', $mails->first()->getSubject());
        $this->assertStringContainsString('Your offer on "Logo" was declined.', html_entity_decode((string) $mails->first()->getHtmlBody(), ENT_QUOTES));
        $this->assertStringContainsString('/projects/5', $mails->first()->getHtmlBody());
        $this->assertSame(2, $user->notifications()->count());
        $this->assertNotNull(ActivityEmail::class);
    }

    public function test_notifications_can_be_filtered_by_group_and_unread(): void
    {
        $this->user->notify(new ActivityNotification('proposal.declined', null, ['project' => 'Logo']));
        $this->user->notify(new ActivityNotification('withdrawal.paid', null, ['amount' => 5, 'currency' => 'USD']));
        $this->user->notify(new ActivityNotification('withdrawal.rejected', null, ['amount' => 5, 'currency' => 'USD', 'reason' => 'x']));
        $this->user->notifications()->where('data', 'like', '%withdrawal.paid%')->update(['read_at' => now()]);

        $this->as($this->user)->getJson('/api/notifications?group=payments')->assertJsonCount(2, 'data')
            ->assertJsonPath('unreadByGroup.payments', 1)->assertJsonPath('unreadByGroup.proposals', 1)->assertJsonPath('unreadByGroup.disputes', 0);
        $this->as($this->user)->getJson('/api/notifications?group=payments&unread=1')->assertJsonCount(1, 'data')->assertJsonPath('data.0.type', 'withdrawal.rejected');
        $this->as($this->user)->getJson('/api/notifications?group=nope')->assertJsonValidationErrors('group');
    }

    public function test_two_factor_by_phone_guards_every_login(): void
    {
        Notification::fake();

        $this->as($this->user)->postJson('/api/me/two-factor', ['method' => 'phone', 'password' => 'wrong', 'target' => '+966501234567'])->assertJsonValidationErrors('password');
        $this->as($this->user)->postJson('/api/me/two-factor', ['method' => 'phone', 'password' => self::PASSWORD, 'target' => '0501'])->assertJsonValidationErrors('target');
        $this->as($this->user)->postJson('/api/me/two-factor', ['method' => 'phone', 'password' => self::PASSWORD, 'target' => '+966501234567'])
            ->assertOk()->assertJsonPath('target', '••••••••••567');
        $this->as($this->user)->postJson('/api/me/two-factor/resend')->assertStatus(429);
        $this->travel(61)->seconds();
        $this->as($this->user)->postJson('/api/me/two-factor/resend')->assertOk()->assertExactJson(['sent' => true]);
        $this->as($this->user)->postJson('/api/me/two-factor/confirm', ['code' => '0000'.($this->lastCode() === '0000' ? '1' : '')])->assertStatus(422);
        $this->as($this->user)->postJson('/api/me/two-factor/confirm', ['code' => $this->lastCode()])
            ->assertOk()->assertJsonPath('twoFactor.enabled', true)->assertJsonPath('twoFactor.method', 'phone');
        $this->assertNotNull($this->user->fresh()->phone_verified_at, 'the code proved the phone is theirs');

        // Login by email now needs the code sent to the phone.
        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/login', ['method' => 'email', 'identifier' => 'sara@example.com', 'password' => self::PASSWORD])
            ->assertOk()->assertJsonPath('otpRequired', true)->assertJsonPath('twoFactor.method', 'phone');
        Notification::assertSentOnDemand(OtpCode::class, fn ($n, $channels, $notifiable) => $notifiable->routes['whatsapp'] === '+966501234567');
        $this->postJson('/api/auth/otp/verify', ['purpose' => 'login', 'method' => 'email', 'target' => 'sara@example.com', 'code' => $this->lastCode()])
            ->assertOk()->assertJsonStructure(['accessToken']);

        $this->app['auth']->forgetGuards();
        $this->as($this->user)->postJson('/api/me/two-factor/disable', ['password' => self::PASSWORD])->assertOk()->assertJsonPath('twoFactor.enabled', false);
    }

    public function test_devices_are_listed_and_can_be_logged_out(): void
    {
        $this->withHeader('User-Agent', 'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/129.0 Safari/537.36')
            ->postJson('/api/auth/login', ['method' => 'phone', 'identifier' => '+966501234567', 'password' => self::PASSWORD]);
        $this->user->forceFill(['phone_verified_at' => now()])->save();
        $phone = $this->withHeader('User-Agent', 'Mozilla/5.0 (iPhone; CPU iPhone OS 17_0 like Mac OS X) Safari/604.1')
            ->postJson('/api/auth/login', ['method' => 'phone', 'identifier' => '+966501234567', 'password' => self::PASSWORD])->json('accessToken');
        $laptop = $this->withHeader('User-Agent', 'Mozilla/5.0 (Windows NT 10.0) Firefox/131.0')
            ->postJson('/api/auth/login', ['method' => 'phone', 'identifier' => '+966501234567', 'password' => self::PASSWORD])->json('accessToken');

        $this->app['auth']->forgetGuards();
        $sessions = $this->withToken($laptop)->getJson('/api/me/security')->assertOk()->json('sessions');
        $this->assertCount(2, $sessions);
        $this->assertEqualsCanonicalizing(['Safari on iOS', 'Firefox on Windows'], array_column($sessions, 'device'));
        $this->assertSame([false, true], collect($sessions)->sortBy('id')->pluck('isCurrent')->values()->all());

        $phoneId = collect($sessions)->firstWhere('isCurrent', false)['id'];
        $this->withToken($laptop)->deleteJson("/api/me/sessions/{$phoneId}")->assertOk()->assertJsonPath('wasCurrent', false);
        $this->app['auth']->forgetGuards();
        $this->withToken($phone)->getJson('/api/me/summary')->assertUnauthorized();
        $this->app['auth']->forgetGuards();
        $this->withToken($laptop)->postJson('/api/me/sessions/logout-others')->assertOk()->assertJsonPath('count', 0);
    }

    private function lastCode(): string
    {
        return Notification::sent(new AnonymousNotifiable, OtpCode::class)->last()->code;
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
