<?php

namespace Tests\Feature;

use App\Models\Page;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PageSeoTest extends TestCase
{
    use RefreshDatabase;

    public function test_admins_set_a_search_title_description_and_noindex_per_page_and_language(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/pages/fees', [
            'section' => 'help', 'titleEn' => 'Site fees', 'titleAr' => 'رسوم الموقع', 'bodyEn' => 'Fees are {{site_fee}}%.', 'bodyAr' => 'الرسوم',
            'metaTitleEn' => 'Mutamakin fees explained', 'metaTitleAr' => 'شرح رسوم متمكن',
            'metaDescriptionEn' => 'What clients and freelancers pay.', 'noindex' => true,
        ])->assertOk()->assertJsonPath('data.metaTitleEn', 'Mutamakin fees explained')->assertJsonPath('data.noindex', true);

        $this->getJson('/api/pages/fees')->assertOk()
            ->assertJsonPath('data.seo', ['title' => 'Mutamakin fees explained', 'description' => 'What clients and freelancers pay.', 'noindex' => true]);
        $this->withHeader('Accept-Language', 'ar')->getJson('/api/pages/fees')
            ->assertJsonPath('data.seo.title', 'شرح رسوم متمكن')
            ->assertJsonPath('data.seo.description', null);
    }

    public function test_pages_without_seo_fields_fall_back_to_their_own_title(): void
    {
        Page::create(['slug' => 'guide', 'section' => 'help', 'title_en' => 'Guide', 'title_ar' => 'الدليل', 'body_en' => 'x', 'body_ar' => 'x']);

        $this->getJson('/api/pages/guide')->assertJsonPath('data.seo', ['title' => null, 'description' => null, 'noindex' => false]);
    }

    public function test_the_default_search_description_is_set_in_site_settings(): void
    {
        $admin = User::factory()->create(['is_admin' => true]);
        $this->actingAs($admin, 'sanctum')->putJson('/api/admin/site-settings', ['seo' => ['descriptionEn' => 'Hire skilled freelancers safely.']])
            ->assertOk()->assertJsonPath('data.seo.descriptionEn', 'Hire skilled freelancers safely.');

        $this->getJson('/api/site-settings')->assertJsonPath('data.seo.descriptionEn', 'Hire skilled freelancers safely.');
    }
}
