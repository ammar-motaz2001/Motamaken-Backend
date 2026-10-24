<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Payments\LedgerService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AdminCurrencyAndFooterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create(['is_admin' => true]);
    }

    public function test_admins_add_reorder_and_switch_off_currencies(): void
    {
        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/currencies')->assertOk()->json('data');
        $this->assertSame(['SAR', 'USD', 'AED', 'EUR'], array_column($list, 'code'));

        $list[] = ['code' => 'KWD', 'symbol' => 'KD', 'nameEn' => 'Kuwaiti Dinar', 'nameAr' => 'دينار كويتي', 'active' => true];
        $list[3]['active'] = false;
        $this->putJson('/api/admin/currencies', ['currencies' => array_reverse($list)])->assertOk()->assertJsonPath('data.0.code', 'KWD');

        $this->assertSame(['KWD', 'AED', 'USD', 'SAR'], array_column($this->getJson('/api/currencies')->json(), 'code'));
        $this->assertSame(['KWD', 'AED', 'USD', 'SAR'], array_column(config('marketplace.currencies'), 'code'));
    }

    public function test_a_currency_users_hold_money_in_stays_on(): void
    {
        $ledger = app(LedgerService::class);
        $ledger->wallet(User::factory()->create(), 'EUR')->forceFill(['balance_cents' => 500])->save();

        $list = $this->actingAs($this->admin, 'sanctum')->getJson('/api/admin/currencies')->json('data');
        $this->assertTrue($list[3]['inUse']);
        $list[3]['active'] = false;
        $this->putJson('/api/admin/currencies', ['currencies' => $list])->assertJsonPath('code', 'currency_in_use');

        $off = array_map(fn ($currency) => [...$currency, 'active' => false], $list);
        $this->putJson('/api/admin/currencies', ['currencies' => $off])->assertJsonPath('code', 'currency_needed');
        $this->putJson('/api/admin/currencies', ['currencies' => [[...$list[0], 'code' => 'riyal']]])->assertUnprocessable();
    }

    public function test_admins_replace_and_restore_the_footer_links(): void
    {
        $footer = [[
            'titleEn' => 'Company',
            'titleAr' => 'الشركة',
            'links' => [
                ['labelEn' => 'FAQ', 'labelAr' => 'الأسئلة الشائعة', 'href' => '/faq'],
                ['labelEn' => 'Blog', 'labelAr' => 'المدونة', 'href' => 'https://blog.example.com'],
            ],
        ]];

        $this->actingAs($this->admin, 'sanctum')->putJson('/api/admin/site-settings', ['footer' => $footer])->assertOk()->assertJsonPath('data.footer.0.links.1.href', 'https://blog.example.com');
        $this->getJson('/api/site-settings')->assertJsonPath('data.footer.0.titleAr', 'الشركة');

        $bad = $footer;
        $bad[0]['links'][0]['href'] = 'javascript:alert(1)';
        $this->putJson('/api/admin/site-settings', ['footer' => $bad])->assertUnprocessable();

        $this->putJson('/api/admin/site-settings', ['footer' => null])->assertOk()->assertJsonPath('data.footer', null);
        $this->getJson('/api/site-settings')->assertJsonPath('data.footer', null);
    }
}
