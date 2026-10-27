<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Setting;
use App\Models\User;
use Database\Seeders\PagesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class FavoritesAndPagesTest extends TestCase
{
    use RefreshDatabase;

    // ---- favorites -------------------------------------------------------------------------------------------------------

    public function test_saving_and_removing_favorites(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $profile = Profile::factory()->create();

        $this->as($user)->postJson('/api/favorites', ['type' => 'project', 'id' => $project->id])->assertCreated()->assertExactJson(['saved' => true]);
        $this->as($user)->postJson('/api/favorites', ['type' => 'project', 'id' => $project->id])->assertOk();
        $this->as($user)->postJson('/api/favorites', ['type' => 'profile', 'id' => $profile->id])->assertCreated();

        $this->as($user)->getJson('/api/me/favorites/ids')->assertExactJson(['projects' => [$project->id], 'profiles' => [$profile->id]]);
        $this->as($user)->getJson('/api/me/favorites')->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.type', 'project')->assertJsonPath('data.0.item.id', $project->id)->assertJsonPath('data.0.item.title', $project->title);
        $this->as($user)->getJson('/api/me/favorites?type=profile')->assertJsonPath('data.0.item.id', $profile->id)
            ->assertJsonPath('data.0.item.fullName', $profile->user->full_name);

        $this->as($user)->deleteJson("/api/favorites/project/{$project->id}")->assertOk()->assertExactJson(['saved' => false]);
        $this->as($user)->deleteJson("/api/favorites/project/{$project->id}")->assertOk();
        $this->as($user)->getJson('/api/me/favorites/ids')->assertJsonPath('projects', []);
        $this->as(User::factory()->create())->getJson('/api/me/favorites/ids')->assertExactJson(['projects' => [], 'profiles' => []]);
    }

    public function test_favorite_rules(): void
    {
        $user = User::factory()->create();
        $mine = Project::factory()->create(['user_id' => $user->id]);

        $this->as($user)->postJson('/api/favorites', ['type' => 'project', 'id' => $mine->id])->assertStatus(422)->assertJsonPath('code', 'own_item');
        $this->as($user)->postJson('/api/favorites', ['type' => 'project', 'id' => 999])->assertNotFound();
        $this->as($user)->postJson('/api/favorites', ['type' => 'comment', 'id' => 1])->assertJsonValidationErrors('type');
        $this->as($user)->deleteJson('/api/favorites/comment/1')->assertNotFound();
        $this->as($user)->getJson('/api/me/favorites?type=comment')->assertJsonValidationErrors('type');

        $this->app['auth']->forgetGuards();
        $this->postJson('/api/favorites', ['type' => 'project', 'id' => 1])->assertUnauthorized();
    }

    public function test_a_deleted_project_drops_out_of_the_list(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();
        $this->as($user)->postJson('/api/favorites', ['type' => 'project', 'id' => $project->id])->assertCreated();

        $project->delete();

        $this->as($user)->getJson('/api/me/favorites')->assertJsonPath('data', []);
    }

    // ---- pages -------------------------------------------------------------------------------------------------------------

    public function test_pages_are_listed_and_shown_in_the_request_language_with_live_numbers(): void
    {
        $this->seed(PagesSeeder::class);
        Setting::put('fees.site_percent', 7.5);

        $list = $this->getJson('/api/pages')->assertOk()->json('data');
        $this->assertContains('terms', array_column($list, 'slug'));
        $this->assertSame(['about', 'help', 'legal'], array_values(array_unique(array_column($list, 'section'))));

        $this->getJson('/api/pages/site-fees')->assertOk()->assertJsonPath('data.title', 'Site fees')
            ->assertJsonPath('data.body', fn ($body) => str_contains($body, '| 7.5% (lower on premium plans) |') && ! str_contains($body, '{{'));
        $this->getJson('/api/pages/terms', ['Accept-Language' => 'ar'])->assertJsonPath('data.title', 'الشروط والأحكام');
        $this->getJson('/api/pages/nope')->assertNotFound();

        Page::where('slug', 'terms')->update(['is_published' => false]);
        $this->getJson('/api/pages/terms')->assertNotFound();
        $this->assertNotContains('terms', array_column($this->getJson('/api/pages')->json('data'), 'slug'));

        // Seeding again does not overwrite an admin's edit.
        Page::where('slug', 'guide')->update(['title_en' => 'Edited']);
        $this->seed(PagesSeeder::class);
        $this->assertSame('Edited', Page::firstWhere('slug', 'guide')->title_en);
    }

    public function test_admins_edit_pages(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $body = ['section' => 'help', 'titleEn' => 'FAQ', 'titleAr' => 'الأسئلة الشائعة', 'bodyEn' => 'Fee: {{site_fee}}%', 'bodyAr' => 'الرسوم {{site_fee}}%'];

        $this->as(User::factory()->create())->putJson('/api/admin/pages/faq', $body)->assertNotFound();
        $this->as($admin)->putJson('/api/admin/pages/faq', [])->assertJsonValidationErrors(['section', 'titleEn', 'titleAr', 'bodyEn', 'bodyAr']);
        $this->as($admin)->putJson('/api/admin/pages/Bad_Slug!', $body)->assertNotFound();
        $this->as($admin)->putJson('/api/admin/pages/faq', $body)->assertOk()->assertJsonPath('data.bodyEn', 'Fee: {{site_fee}}%');

        $this->app['auth']->forgetGuards();
        $this->getJson('/api/pages/faq')->assertJsonPath('data.body', 'Fee: 10%');

        $this->as($admin)->getJson('/api/admin/pages')->assertJsonPath('data.0.slug', 'faq')->assertJsonPath('placeholders', fn ($p) => in_array('{{site_fee}}', $p, true));
        $this->as($admin)->getJson('/api/admin/pages/faq')->assertJsonPath('data.titleAr', 'الأسئلة الشائعة');
        $this->as($admin)->deleteJson('/api/admin/pages/faq')->assertOk();
        $this->as($admin)->getJson('/api/admin/pages/faq')->assertNotFound();
    }

    // ---- contact ------------------------------------------------------------------------------------------------------------

    public function test_visitors_and_users_send_contact_messages_and_admins_handle_them(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $message = ['topic' => 'payments', 'subject' => 'My deposit', 'message' => 'My card was charged but the wallet shows nothing.'];

        $this->postJson('/api/contact', $message)->assertJsonValidationErrors(['name', 'email']);
        $this->postJson('/api/contact', [...$message, 'name' => 'Visitor Vee', 'email' => 'vee@example.com'])->assertCreated()->assertExactJson(['success' => true]);
        $this->postJson('/api/contact', [...$message, 'name' => 'V', 'email' => 'x', 'topic' => 'pizza'])->assertJsonValidationErrors(['name', 'email', 'topic']);

        $user = User::factory()->create(['first_name' => 'Uma', 'last_name' => 'User', 'email' => 'uma@example.com']);
        $this->as($user)->postJson('/api/contact', $message)->assertCreated();

        $this->as($admin)->getJson('/api/notifications')->assertJsonPath('data.0.message', 'New contact message from Uma User: "My deposit".');
        $list = $this->as($admin)->getJson('/api/admin/contact-messages')->assertJsonPath('meta.total', 2)->json('data');
        $this->assertSame(['Uma User', 'Visitor Vee'], array_column($list, 'name'));
        $this->assertSame('uma@example.com', $list[0]['email']);

        $this->as($user)->getJson('/api/admin/contact-messages')->assertNotFound();
        $this->as($admin)->postJson("/api/admin/contact-messages/{$list[0]['id']}/handle", ['note' => 'Refunded'])->assertOk()
            ->assertJsonPath('data.status', 'handled')->assertJsonPath('data.adminNote', 'Refunded');
        $this->as($admin)->getJson('/api/admin/contact-messages')->assertJsonPath('meta.total', 1);
        $this->as($admin)->getJson('/api/admin/contact-messages?status=handled')->assertJsonPath('meta.total', 1);
    }

    public function test_the_contact_form_is_rate_limited(): void
    {
        $message = ['name' => 'Spam Sam', 'email' => 'sam@example.com', 'topic' => 'other', 'subject' => 'Hello', 'message' => 'Buy cheap followers now!!'];

        foreach (range(1, 5) as $_) {
            $this->postJson('/api/contact', $message)->assertCreated();
        }

        $this->postJson('/api/contact', $message)->assertStatus(429);
    }

    private function as(User $user): static
    {
        return $this->actingAs($user, 'sanctum');
    }
}
