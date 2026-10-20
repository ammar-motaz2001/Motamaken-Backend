<?php

namespace Tests\Feature;

use App\Models\Category;
use App\Models\City;
use App\Models\ContactMessage;
use App\Models\Country;
use App\Models\Medal;
use App\Models\PointTransaction;
use App\Models\Profile;
use App\Models\User;
use App\Notifications\ContactReply;
use App\Services\RewardsService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AdminContentTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_subcategories_and_archived_categories(): void
    {
        $design = Category::factory()->create(['type' => 'project', 'slug' => 'design']);

        $logo = $this->as($this->admin)->postJson('/api/admin/categories', [
            'type' => 'project', 'slug' => 'logo-design', 'nameEn' => 'Logo design', 'nameAr' => 'تصميم شعارات', 'parentId' => $design->id,
        ])->assertOk()->assertJsonPath('data.parentId', $design->id)->json('data.id');

        $this->as($this->admin)->patchJson("/api/admin/categories/{$design->id}", ['parentId' => $logo])->assertStatus(422);
        $this->getJson('/api/categories?type=project')->assertJsonFragment(['parentId' => $design->id]);

        $this->as($this->admin)->patchJson("/api/admin/categories/{$logo}", ['isActive' => false])->assertOk()->assertJsonPath('data.isActive', false);
        $this->app['auth']->forgetGuards();
        $this->assertNotContains($logo, array_column($this->getJson('/api/categories?type=project')->json('data'), 'id'));
        $this->as(User::factory()->create())->postJson('/api/projects', [
            'type' => 'project', 'categoryId' => $logo, 'title' => 'A new logo', 'description' => 'We need a modern logo for our shop.', 'budgetMin' => 50, 'budgetMax' => 100,
        ])->assertJsonValidationErrors('categoryId');
    }

    public function test_skills_are_curated_and_suggested_first(): void
    {
        Profile::factory()->create(['skills' => ['Laravel', 'Figma']]);

        $this->as($this->admin)->postJson('/api/admin/skills', ['nameEn' => 'Copywriting', 'nameAr' => 'كتابة إعلانية'])->assertCreated()->assertJsonPath('data.slug', 'copywriting');
        $this->as($this->admin)->getJson('/api/admin/skills')->assertOk()->assertJsonCount(1, 'data')
            ->assertJsonPath('suggestions.0.profiles', 1);

        $this->app['auth']->forgetGuards();
        $this->assertSame('Copywriting', $this->getJson('/api/search/skills')->json('data.0'));
        $this->assertSame(['كتابة إعلانية'], $this->getJson('/api/search/skills?q=كتابة', ['Accept-Language' => 'ar'])->json('data'));
    }

    public function test_countries_and_cities_can_be_switched_off(): void
    {
        $sa = Country::factory()->create(['code' => 'SA']);
        $city = City::factory()->create(['country_id' => $sa->id]);

        $this->as($this->admin)->patchJson("/api/admin/countries/{$sa->id}", ['isActive' => false])->assertOk();
        $this->as($this->admin)->postJson("/api/admin/countries/{$sa->id}/cities", ['nameEn' => 'Abha', 'nameAr' => 'أبها'])->assertCreated();
        $this->as($this->admin)->patchJson("/api/admin/cities/{$city->id}", ['isActive' => false])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->assertNotContains($sa->id, array_column($this->getJson('/api/countries')->json('data'), 'id'));
        $this->assertSame(['Abha'], array_column($this->getJson("/api/countries/{$sa->id}/cities")->json('data'), 'name'));
    }

    public function test_the_faq_is_managed_and_searched(): void
    {
        $group = $this->as($this->admin)->postJson('/api/admin/faq-categories', ['nameEn' => 'Payments', 'nameAr' => 'المدفوعات'])->assertCreated()->json('data.id');
        $this->as($this->admin)->postJson('/api/admin/faqs', [
            'categoryId' => $group, 'questionEn' => 'How do withdrawals work?', 'questionAr' => 'كيف يتم السحب؟',
            'answerEn' => 'Request one from your wallet.', 'answerAr' => 'اطلبه من محفظتك.', 'audience' => 'freelancer',
        ])->assertCreated();
        $hidden = $this->as($this->admin)->postJson('/api/admin/faqs', [
            'categoryId' => $group, 'questionEn' => 'Draft question', 'questionAr' => 'سؤال', 'answerEn' => 'Soon', 'answerAr' => 'قريبًا', 'isPublished' => false,
        ])->json('data.id');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/faqs')->assertOk()->assertJsonCount(1, 'data.0.faqs')->assertJsonPath('data.0.name', 'Payments');
        $this->getJson('/api/faqs', ['Accept-Language' => 'ar'])->assertJsonPath('data.0.faqs.0.question', 'كيف يتم السحب؟');
        $this->getJson('/api/faqs?q=withdraw')->assertJsonCount(1, 'data');
        $this->getJson('/api/faqs?q=nothing-like-this')->assertJsonCount(0, 'data');
        $this->getJson('/api/faqs?audience=client')->assertJsonCount(0, 'data');
        $this->as($this->admin)->getJson('/api/admin/faqs')->assertJsonCount(2, 'data.0.faqs');
        $this->as($this->admin)->deleteJson("/api/admin/faqs/{$hidden}")->assertOk();
    }

    public function test_contact_messages_are_answered_by_email_searched_and_archived(): void
    {
        Notification::fake();
        $message = ContactMessage::create(['name' => 'Huda', 'email' => 'huda@example.com', 'topic' => 'payments', 'subject' => 'Refund question', 'message' => 'Where is my refund for last week?']);
        ContactMessage::create(['name' => 'Ali', 'email' => 'ali@example.com', 'topic' => 'general', 'subject' => 'Hello', 'message' => 'Just saying hi to the team.']);

        $this->as($this->admin)->getJson('/api/admin/contact-messages?q=refund')->assertJsonCount(1, 'data');
        $this->as($this->admin)->postJson("/api/admin/contact-messages/{$message->id}/reply", ['body' => 'It was sent to your bank yesterday.'])->assertOk()
            ->assertJsonPath('data.status', 'handled')
            ->assertJsonPath('data.reply', 'It was sent to your bank yesterday.');
        Notification::assertSentOnDemand(ContactReply::class, fn ($n, $channels, $notifiable) => array_key_exists('huda@example.com', $notifiable->routes['mail']));

        $this->as($this->admin)->postJson("/api/admin/contact-messages/{$message->id}/archive", ['archived' => true])->assertOk();
        $this->as($this->admin)->getJson('/api/admin/contact-messages?archived=1')->assertJsonCount(1, 'data')->assertJsonPath('counts.archived', 1);
    }

    public function test_site_settings_feed_the_website(): void
    {
        Storage::fake('public');

        $this->as($this->admin)->putJson('/api/admin/site-settings', [
            'site' => ['nameEn' => 'Mutamakin', 'email' => 'hello@mutamakin.com', 'phone' => '+966500000000'],
            'social' => ['x' => 'https://x.com/mutamakin', 'instagram' => 'https://instagram.com/mutamakin'],
            'apps' => ['android' => 'https://play.google.com/store/apps/details?id=com.mutamakin'],
            'uploads' => ['chatMb' => 5, 'attachmentTypes' => ['pdf', 'png']],
            'payments' => ['depositMethods' => ['mada', 'card']],
        ])->assertOk()->assertJsonPath('data.payments.depositMethods', ['mada', 'card']);
        $this->as($this->admin)->putJson('/api/admin/site-settings', ['uploads' => ['attachmentTypes' => ['exe']]])->assertJsonValidationErrors('uploads.attachmentTypes.0');
        $this->as($this->admin)->post('/api/admin/site-settings/logo', ['logo' => UploadedFile::fake()->create('logo.png', 20, 'image/png')], ['Accept' => 'application/json'])->assertOk();

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/site-settings')->assertOk()
            ->assertJsonPath('data.email', 'hello@mutamakin.com')
            ->assertJsonPath('data.social.x', 'https://x.com/mutamakin')
            ->assertJsonPath('data.apps.android', 'https://play.google.com/store/apps/details?id=com.mutamakin')
            ->assertJsonPath('data.uploads.chatMb', 5)
            ->assertJsonPath('data.depositMethods', ['mada', 'card'])
            ->assertJsonPath('data.logoUrl', fn ($url) => str_contains($url, '/storage/branding/'));

        // The new limits apply to uploads straight away.
        $this->as(User::factory()->create())->postJson('/api/support', [
            'subject' => 'Broken page', 'category' => 'technical', 'body' => 'The wallet page does not load for me.',
            'file' => UploadedFile::fake()->create('notes.docx', 10),
        ])->assertJsonValidationErrors('file');
    }

    public function test_the_blacklist_blocks_emails_words_and_addresses(): void
    {
        $this->as($this->admin)->postJson('/api/admin/blacklist', ['type' => 'domain', 'value' => '@spam.test', 'reason' => 'Throwaway domain'])->assertCreated()
            ->assertJsonPath('data.value', 'spam.test');
        $this->as($this->admin)->postJson('/api/admin/blacklist', ['type' => 'keyword', 'value' => 'WhatsApp me', 'reason' => 'Off-platform deals'])->assertCreated();
        $ip = $this->as($this->admin)->postJson('/api/admin/blacklist', ['type' => 'ip', 'value' => '10.9.8.7', 'reason' => 'Bot'])->json('data.id');
        $this->as($this->admin)->postJson('/api/admin/blacklist', ['type' => 'email', 'value' => 'not-an-email', 'reason' => 'x'])->assertJsonValidationErrors('value');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/auth/quick/start', ['method' => 'email', 'email' => 'someone@spam.test'])->assertJsonValidationErrors('email');

        $category = Category::factory()->create(['type' => 'project']);
        $this->as(User::factory()->create())->postJson('/api/projects', [
            'type' => 'project', 'categoryId' => $category->id, 'title' => 'Quick job', 'description' => 'Easy task, just whatsapp me and we will agree on the price.',
            'budgetMin' => 10, 'budgetMax' => 20,
        ])->assertJsonValidationErrors('description');

        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->postJson('/api/contact', [])->assertForbidden()->assertJsonPath('code', 'blocked');
        $this->withServerVariables(['REMOTE_ADDR' => '10.9.8.7'])->getJson('/api/projects')->assertOk();

        $this->withServerVariables(['REMOTE_ADDR' => '127.0.0.1']);
        $this->as($this->admin)->getJson('/api/admin/blacklist')->assertJsonCount(3, 'data');
        $this->as($this->admin)->deleteJson("/api/admin/blacklist/{$ip}")->assertOk();
    }

    public function test_point_rules_manual_points_and_the_points_report(): void
    {
        $user = User::factory()->create();

        $this->as($this->admin)->putJson('/api/admin/points/rules', ['rules' => ['review_left' => ['amount' => 35]]])->assertOk()
            ->assertJsonFragment(['reason' => 'review_left', 'amount' => 35]);
        app(RewardsService::class)->award($user, 'review_left');
        $this->assertSame(35, $user->fresh()->points);

        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/points", ['amount' => 15, 'note' => 'Thanks for the bug report'])->assertOk()->assertJsonPath('data.balance', 50);
        $this->as($this->admin)->postJson("/api/admin/users/{$user->id}/points", ['amount' => -100, 'note' => 'Too much'])->assertJsonPath('code', 'insufficient_points');
        $this->assertSame($this->admin->id, PointTransaction::where('reason', 'admin_adjustment')->value('created_by'));

        $this->as($user)->getJson('/api/me/points')->assertJsonPath('data.0.label', 'Thanks for the bug report');
        $this->as($this->admin)->getJson('/api/admin/points/report')->assertOk()->assertJsonPath('data.outstanding', 50);
        $this->as($this->admin)->getJson('/api/admin/points/transactions?direction=earned')->assertJsonCount(2, 'data');
    }

    public function test_medals_are_created_given_by_hand_and_switched_off(): void
    {
        $user = User::factory()->create();

        $this->as($this->admin)->getJson('/api/admin/medals')->assertOk()->assertJsonCount(8, 'data');
        $medal = $this->as($this->admin)->postJson('/api/admin/medals', ['code' => 'early_bird', 'nameEn' => 'Early bird', 'nameAr' => 'السبّاق'])
            ->assertCreated()->json('data.id');

        $this->as($this->admin)->postJson("/api/admin/medals/{$medal}/grant", ['userId' => $user->id, 'reason' => 'Joined in the first week'])->assertOk();
        $this->as($this->admin)->postJson("/api/admin/medals/{$medal}/grant", ['userId' => $user->id, 'reason' => 'Again'])->assertJsonPath('code', 'medal_held');
        $this->as($this->admin)->getJson("/api/admin/medals/{$medal}/holders")->assertJsonPath('data.0.reason', 'Joined in the first week');

        $mine = collect($this->as($user)->getJson('/api/me/medals')->json('data'))->keyBy('code');
        $this->assertTrue($mine['early_bird']['earned']);
        $this->assertSame('Early bird', $mine['early_bird']['name']);
        $this->as($user)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'You earned the "Early bird" medal!');

        // Switched off: no longer offered, but the holder keeps it.
        $this->as($this->admin)->patchJson('/api/admin/medals/'.Medal::where('code', 'connector')->value('id'), ['isActive' => false])->assertOk();
        $this->as($this->admin)->patchJson("/api/admin/medals/{$medal}", ['isActive' => false])->assertOk();
        $codes = array_column($this->as($user)->getJson('/api/me/medals')->json('data'), 'code');
        $this->assertContains('early_bird', $codes);
        $this->assertNotContains('connector', $codes);

        $this->as($this->admin)->deleteJson("/api/admin/medals/{$medal}/holders/{$user->id}")->assertOk();
    }

    public function test_suspicious_referrals_can_be_revoked(): void
    {
        $referrer = User::factory()->create(['registration_ip' => '1.2.3.4']);
        $invited = User::factory()->create(['referred_by' => $referrer->id, 'registration_ip' => '1.2.3.4']);
        app(RewardsService::class)->award($referrer, 'referral', "referral:{$invited->id}");

        $this->as($this->admin)->getJson('/api/admin/affiliate/suspicious')->assertOk()
            ->assertJsonPath('data.0.user.id', $invited->id)
            ->assertJsonPath('data.0.reasons', ['same_signup_ip']);
        $this->as($this->admin)->postJson("/api/admin/affiliate/referrals/{$invited->id}/revoke", ['reason' => 'Self-referral'])->assertOk();
        $this->assertSame(0, $referrer->fresh()->points);
        $this->assertNull($invited->fresh()->referred_by);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
