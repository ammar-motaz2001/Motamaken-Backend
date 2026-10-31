<?php

namespace Tests\Feature;

use App\Listeners\EmailActivityNotification;
use App\Models\Category;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Notifications\ActivityEmail;
use App\Notifications\ContactReceived;
use App\Notifications\ProposalActivity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\AnonymousNotifiable;
use Illuminate\Notifications\Events\NotificationSent;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class ActivityEmailTest extends TestCase
{
    use RefreshDatabase;

    public function test_publishing_and_offering_confirm_by_bell_and_the_owner_is_emailed_about_offers(): void
    {
        $owner = User::factory()->create();
        $freelancer = User::factory()->create();
        Profile::factory()->create(['user_id' => $freelancer->id]);
        $category = Category::factory()->create();

        $projectId = $this->actingAs($owner, 'sanctum')->postJson('/api/projects', [
            'type' => 'project', 'categoryId' => $category->id, 'title' => 'Landing page for a bakery',
            'description' => 'A one-page site with the menu, opening hours and a contact form.', 'budgetMin' => 100, 'budgetMax' => 500, 'currency' => 'USD',
        ])->assertCreated()->json('data.id');
        $this->assertContains('project.published', $owner->notifications()->pluck('data')->pluck('type')->all());

        $this->app['auth']->forgetGuards();
        $this->actingAs($freelancer, 'sanctum')->postJson("/api/projects/{$projectId}/proposals", ['amount' => 300, 'deliveryDays' => 5, 'message' => 'I can build this for you quickly.'])->assertCreated();

        $this->assertContains('proposal.sent', $freelancer->notifications()->pluck('data')->pluck('type')->all());
    }

    public function test_offer_emails_are_sent_to_the_owner(): void
    {
        Notification::fake();
        $owner = User::factory()->create();
        $offer = new ProposalActivity(ProposalActivity::RECEIVED, User::factory()->create(), Project::factory()->create(['user_id' => $owner->id]), Proposal::factory()->create());

        (new EmailActivityNotification)->handle(new NotificationSent($owner, $offer, 'database'));

        Notification::assertSentTo($owner, ActivityEmail::class);
    }

    public function test_the_contact_form_sender_gets_a_confirmation(): void
    {
        Notification::fake();

        $this->postJson('/api/contact', ['name' => 'Sara', 'email' => 'sara@example.com', 'topic' => 'general', 'subject' => 'A question', 'message' => 'How do withdrawals work here?'])->assertCreated();

        Notification::assertSentTo(new AnonymousNotifiable, ContactReceived::class, fn ($notification, $channels, $notifiable) => $notifiable->routes['mail'] === 'sara@example.com');
    }
}
