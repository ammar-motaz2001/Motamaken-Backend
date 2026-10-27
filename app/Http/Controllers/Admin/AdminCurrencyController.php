<?php

namespace App\Http\Controllers\Admin;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Account;
use App\Models\Setting;
use App\Support\RuntimeConfig;
use Dedoc\Scramble\Attributes\Group;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminCurrencyController extends Controller
{
    /**
     * List currencies.
     *
     * Every currency the marketplace knows, in display order, with whether it is offered (projects, offers, wallets,
     * the currency menu) and how much money users hold in it.
     *
     * @return array{data: list<array{code: string, symbol: string, nameEn: string, nameAr: string, active: bool, inUse: bool}>}
     */
    public function index(): array
    {
        return ['data' => $this->current()];
    }

    /**
     * Save currencies.
     *
     * The whole list, in display order. Add a currency with its ISO code (e.g. `KWD`); switch one off to stop offering
     * it. A currency users still hold money in cannot be switched off or removed, and at least one stays on.
     *
     * @return array{data: list<array{code: string, symbol: string, nameEn: string, nameAr: string, active: bool, inUse: bool}>}
     */
    public function update(Request $request): array
    {
        $data = $request->validate([
            'currencies' => ['required', 'array', 'min:1', 'max:30'],
            /** ISO 4217 code, three capital letters. */
            'currencies.*.code' => ['required', 'string', 'regex:/^[A-Z]{3}$/', 'distinct'],
            'currencies.*.symbol' => ['required', 'string', 'max:6'],
            'currencies.*.nameEn' => ['required', 'string', 'max:40'],
            'currencies.*.nameAr' => ['required', 'string', 'max:40'],
            'currencies.*.active' => ['required', 'boolean'],
            /** Units of this currency per one US dollar (USD is 1), for showing prices in the visitor's currency. */
            'currencies.*.rate' => ['sometimes', 'numeric', 'gt:0', 'max:1000000'],
        ]);

        $list = array_map(fn (array $currency) => [
            'code' => $currency['code'],
            'symbol' => trim($currency['symbol']),
            'name_en' => trim($currency['nameEn']),
            'name_ar' => trim($currency['nameAr']),
            'active' => (bool) $currency['active'],
            'rate' => $currency['code'] === 'USD' ? 1.0 : (float) ($currency['rate'] ?? RuntimeConfig::defaultRate($currency['code'])),
        ], $data['currencies']);

        if (! in_array(true, array_column($list, 'active'), true)) {
            throw new ApiException(__('admin.currency_needed'), 'currency_needed');
        }
        $offered = array_column(array_filter($list, fn (array $currency) => $currency['active']), 'code');
        foreach ($this->heldCurrencies() as $code) {
            if (! in_array($code, $offered, true)) {
                throw new ApiException(__('admin.currency_in_use', ['code' => $code]), 'currency_in_use');
            }
        }

        request()->attributes->set('audit.before', $this->current());
        Setting::put('currencies.list', $list);
        RuntimeConfig::apply();
        Cache::forget('site-settings');

        return ['data' => $this->current()];
    }

    /**
     * The saved list, or the built-in currencies (all on) until the admins change it.
     *
     * @return list<array{code: string, symbol: string, nameEn: string, nameAr: string, active: bool, inUse: bool}>
     */
    private function current(): array
    {
        $list = Setting::get('currencies.list') ?? array_map(fn (array $currency) => [...$currency, 'active' => true], config('marketplace.currencies'));
        $held = $this->heldCurrencies();

        return array_values(array_map(fn (array $currency) => [
            'code' => $currency['code'],
            'symbol' => $currency['symbol'],
            'nameEn' => $currency['name_en'],
            'nameAr' => $currency['name_ar'],
            'active' => (bool) $currency['active'],
            'rate' => (float) ($currency['rate'] ?? RuntimeConfig::defaultRate($currency['code'])),
            'inUse' => in_array($currency['code'], $held, true),
        ], $list));
    }

    /**
     * Currencies some wallet, escrow or other account still holds money in.
     *
     * @return list<string>
     */
    private function heldCurrencies(): array
    {
        return Account::where('balance_cents', '!=', 0)->distinct()->pluck('currency')->all();
    }
}
