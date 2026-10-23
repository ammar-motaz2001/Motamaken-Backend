<?php

namespace Tests\Feature;

use App\Console\Commands\SeedDemoData;
use App\Models\Category;
use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class SeedDemoDataTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_fills_a_tagged_marketplace_without_reviews_money_or_notifications_and_purges_it(): void
    {
        Notification::fake();
        Category::factory()->create(['type' => 'project', 'slug' => 'website-it-software']);
        Category::factory()->create(['type' => 'influencer', 'slug' => 'instagram']);
        $real = User::factory()->create();

        $this->artisan('demo:seed', ['--users' => 60, '--projects' => 20, '--force' => true])->assertSuccessful();

        $this->assertSame(60, User::where('email', 'like', '%@'.SeedDemoData::DOMAIN)->count());
        $this->assertSame(20, Project::count());
        $this->assertGreaterThan(0, DB::table('proposals')->count());
        $this->assertSame(0, DB::table('reviews')->count());
        $this->assertSame(0, DB::table('ledger_entries')->count());
        Notification::assertNothingSent();

        $this->artisan('demo:seed', ['--purge' => true, '--force' => true])->assertSuccessful();
        $this->assertSame(0, User::where('email', 'like', '%@'.SeedDemoData::DOMAIN)->count());
        $this->assertSame(0, Project::count());
        $this->assertSame(0, DB::table('conversations')->count());
        $this->assertTrue($real->fresh()->exists);
    }
}
