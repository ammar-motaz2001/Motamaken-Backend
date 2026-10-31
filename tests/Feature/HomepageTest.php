<?php

namespace Tests\Feature;

use App\Models\Account;
use App\Models\Category;
use App\Models\City;
use App\Models\Country;
use App\Models\Profile;
use App\Models\Project;
use App\Models\User;
use App\Services\Payments\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HomepageTest extends TestCase
{
    use RefreshDatabase;

    public function test_home_returns_the_sections_for_visitors(): void
    {
        $web = Category::factory()->create(['slug' => 'web', 'name_en' => 'Web', 'sort_order' => 0]);
        Category::factory()->create(['slug' => 'writing', 'sort_order' => 1]);
        $instagram = Category::factory()->influencer()->create(['slug' => 'instagram', 'sort_order' => 0]);

        Profile::factory()->featured()->count(8)->create();
        Profile::factory()->create();
        Project::factory()->featured()->count(7)->create(['category_id' => $web->id]);
        Project::factory()->count(6)->create(['category_id' => $web->id]);
        Project::factory()->influencerRequest()->count(2)->create(['category_id' => $instagram->id]);

        $response = $this->getJson('/api/home')->assertOk();

        $this->assertCount(6, $response->json('featuredProfiles'), 'the slider shows 6 featured profiles');
        $this->assertCount(6, $response->json('distinctiveProjects'));
        $this->assertSame(['web', 'writing'], array_column($response->json('projectCategories'), 'slug'));
        // Default tab = first category, 5 cards, newest first.
        $this->assertCount(5, $response->json('recentProjects'));
        $this->assertSame(['web'], array_unique(array_column(array_column($response->json('recentProjects'), 'category'), 'slug')));
        $this->assertCount(2, $response->json('latestInfluencerRequests'));
        $response->assertJsonPath('featuredProfiles.0.fullName', fn ($name) => is_string($name) && $name !== '');
    }

    public function test_home_hides_expired_featuring_and_closed_projects(): void
    {
        $category = Category::factory()->create();
        $expired = Profile::factory()->featured()->create(['featured_until' => now()->subDay(), 'rating' => 5, 'job_title' => 'Designer', 'bio' => 'Ten years of brand work.']);
        $expired->user->forceFill(['avatar_path' => 'avatars/1.png'])->save();
        // A new, empty profile never fills the slider, however it is rated.
        Profile::factory()->create(['rating' => 5, 'job_title' => null, 'bio' => null]);
        $paid = Profile::factory()->featured()->create(['featured_until' => now()->addDay(), 'rating' => 1]);
        Project::factory()->featured()->create(['category_id' => $category->id, 'status' => Project::STATUS_CLOSED]);
        $lapsed = Project::factory()->featured()->create(['category_id' => $category->id, 'featured_until' => now()->subMinute()]);

        $response = $this->getJson('/api/home')->assertOk();

        // The paid placement comes first; the rest of the slider is filled with other profiles and open projects.
        $this->assertSame([$paid->id, $expired->id], array_column($response->json('featuredProfiles'), 'id'));
        $this->assertSame([$lapsed->id], array_column($response->json('distinctiveProjects'), 'id'), 'closed projects never show');
        // The project whose featuring expired is still a normal open project in the recent list.
        $this->assertCount(1, $response->json('recentProjects'));
    }

    public function test_home_works_on_an_empty_database(): void
    {
        $this->getJson('/api/home')->assertOk()->assertExactJson([
            'featuredProfiles' => [], 'distinctiveProjects' => [], 'projectCategories' => [],
            'recentProjects' => [], 'influencerPlatforms' => [], 'latestInfluencerRequests' => [],
        ]);
    }

    public function test_projects_can_be_filtered_and_sorted(): void
    {
        $egypt = Country::factory()->create();
        $cairo = City::factory()->create(['country_id' => $egypt->id]);
        $web = Category::factory()->create(['slug' => 'web']);
        $writing = Category::factory()->create(['slug' => 'writing']);
        $insta = Category::factory()->influencer()->create(['slug' => 'instagram']);

        $cheap = Project::factory()->create(['category_id' => $web->id, 'title' => 'Landing page', 'budget_min' => 50, 'budget_max' => 100, 'country_id' => $egypt->id, 'city_id' => $cairo->id]);
        $pricey = Project::factory()->create(['category_id' => $web->id, 'title' => 'Big 100% store', 'budget_min' => 900, 'budget_max' => 1500]);
        Project::factory()->create(['category_id' => $writing->id, 'title' => 'Blog posts']);
        Project::factory()->influencerRequest()->create(['category_id' => $insta->id]);
        Project::factory()->create(['category_id' => $web->id, 'status' => Project::STATUS_CLOSED]);

        $ids = fn (string $query) => array_column($this->getJson("/api/projects?{$query}")->assertOk()->json('data'), 'id');

        $this->assertCount(3, $ids('type=project'), 'closed projects are never listed');
        $this->assertEqualsCanonicalizing([$cheap->id, $pricey->id], $ids('category=web'));
        $this->assertSame([$cheap->id], $ids("countryId={$egypt->id}"));
        $this->assertSame([$cheap->id], $ids("cityId={$cairo->id}"));
        $this->assertSame([$cheap->id], $ids('q=landing'));
        $this->assertSame([$pricey->id], $ids('q='.urlencode('100%')), '% is matched literally');
        $this->assertSame([$pricey->id], $ids('category=web&budgetMin=1000'));
        $this->assertSame([$cheap->id], $ids('category=web&budgetMax=200'));
        $this->assertSame([$pricey->id, $cheap->id], $ids('category=web&sort=budget_high'));
        $this->assertSame([$cheap->id, $pricey->id], $ids('category=web&sort=budget_low'));
        $this->assertCount(1, $ids('type=influencer_request'));

        $this->getJson('/api/projects?category=nope')->assertStatus(422);
        $this->getJson('/api/projects?perPage=500')->assertStatus(422);
        $this->getJson('/api/projects?perPage=1')->assertJsonPath('meta.per_page', 1);
    }

    public function test_project_detail_is_public_but_only_for_open_projects(): void
    {
        $open = Project::factory()->create();
        $closed = Project::factory()->create(['status' => Project::STATUS_CLOSED]);

        $this->getJson("/api/projects/{$open->id}")->assertOk()
            ->assertJsonPath('data.id', $open->id)
            ->assertJsonStructure(['data' => ['title', 'description', 'budget' => ['min', 'max', 'currency'], 'category', 'owner']]);
        $this->getJson("/api/projects/{$closed->id}")->assertNotFound();
    }

    public function test_search_groups_results_and_can_narrow_to_one_type(): void
    {
        $designer = Profile::factory()->create(['job_title' => 'Logo Designer']);
        Profile::factory()->influencer()->create(['job_title' => 'Design influencer']);
        Project::factory()->create(['title' => 'Design a logo']);
        Project::factory()->influencerRequest()->create(['title' => 'Design week promotion']);
        Project::factory()->create(['title' => 'Unrelated']);

        $all = $this->getJson('/api/search?q=design')->assertOk();
        foreach (['projects', 'influencerRequests', 'influencers', 'freelancers'] as $group) {
            $this->assertCount(1, $all->json($group), $group);
        }

        $this->getJson('/api/search?type=freelancers&q=design')->assertOk()
            ->assertJsonPath('data.0.userId', $designer->user_id)
            ->assertJsonPath('meta.total', 1);
        $this->getJson('/api/search?type=projects')->assertOk()->assertJsonPath('meta.total', 2);

        $person = User::factory()->create(['first_name' => 'Hosaam', 'last_name' => 'Amar']);
        Profile::factory()->create(['user_id' => $person->id, 'job_title' => 'Plumber']);
        $this->getJson('/api/search?type=freelancers&q=hosaam')->assertJsonPath('data.0.fullName', 'Hosaam Amar');

        $this->getJson('/api/search?type=bogus')->assertStatus(422);
    }

    public function test_dropdown_data_is_localized(): void
    {
        $egypt = Country::factory()->create(['name_en' => 'Egypt', 'name_ar' => 'مصر']);
        City::factory()->create(['country_id' => $egypt->id, 'name_en' => 'Cairo', 'name_ar' => 'القاهرة']);
        Category::factory()->create(['slug' => 'web', 'name_en' => 'Web', 'name_ar' => 'ويب']);
        Category::factory()->influencer()->create(['slug' => 'x']);

        $this->getJson('/api/countries')->assertJsonPath('data.0.name', 'Egypt');
        $this->getJson('/api/countries', ['Accept-Language' => 'ar'])->assertJsonPath('data.0.name', 'مصر');
        $this->getJson("/api/countries/{$egypt->id}/cities", ['Accept-Language' => 'ar'])->assertJsonPath('data.0.name', 'القاهرة');
        $this->getJson('/api/categories')->assertJsonCount(2, 'data');
        $this->getJson('/api/categories?type=influencer')->assertJsonCount(1, 'data')->assertJsonPath('data.0.slug', 'x');
        $this->getJson('/api/categories?type=nope')->assertStatus(422);

        $this->getJson('/api/currencies')->assertJsonFragment(['code' => 'SAR', 'name' => 'Saudi Riyal']);
        $this->getJson('/api/currencies', ['Accept-Language' => 'ar'])->assertJsonFragment(['code' => 'EUR', 'name' => 'يورو']);
        $this->getJson('/api/languages')->assertJsonFragment(['code' => 'ar', 'label' => 'ARA']);
    }

    public function test_header_summary_and_preferences(): void
    {
        $user = User::factory()->create(['points' => 1908]);
        $ledger = app(LedgerService::class);
        $ledger->post('deposit', [
            [$ledger->system(Account::GATEWAY, 'USD'), -49000],
            [$ledger->wallet($user, 'USD'), 49000],
        ]);

        $this->getJson('/api/me/summary')->assertUnauthorized();

        $this->actingAs($user, 'sanctum')->getJson('/api/me/summary')->assertOk()
            ->assertJsonPath('wallet', ['balance' => 490, 'currency' => 'USD'])
            ->assertJsonPath('points', 1908)
            ->assertJsonPath('unreadMessages', 0)
            ->assertJsonPath('unreadNotifications', 0);

        $this->actingAs($user, 'sanctum')->patchJson('/api/me/preferences', ['locale' => 'fr', 'currency' => 'AED', 'theme' => 'dark'])
            ->assertOk()->assertJsonPath('data.locale', 'fr')->assertJsonPath('data.theme', 'dark');
        $this->assertSame('AED', $user->fresh()->currency);

        // Only what is sent changes.
        $this->actingAs($user, 'sanctum')->patchJson('/api/me/preferences', ['theme' => 'light'])->assertOk();
        $this->assertSame(['fr', 'AED', 'light'], [$user->fresh()->locale, $user->fresh()->currency, $user->fresh()->theme]);

        foreach ([['locale' => 'de'], ['currency' => 'XXX'], ['theme' => 'blue']] as $invalid) {
            $this->actingAs($user, 'sanctum')->patchJson('/api/me/preferences', $invalid)->assertStatus(422);
        }
    }
}
