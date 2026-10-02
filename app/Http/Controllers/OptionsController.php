<?php

namespace App\Http\Controllers;

use Dedoc\Scramble\Attributes\Group;

#[Group('Homepage', 'Public data for the homepage, search bar and category menus. No login needed.', weight: 2)]
class OptionsController extends Controller
{
    /**
     * List currencies.
     *
     * For the currency dropdown in the header (SAR, USD, AED, EURO).
     *
     * @return list<array{code: string, symbol: string, name: string}>
     *
     * @unauthenticated
     */
    public function currencies(): array
    {
        $name = app()->getLocale() === 'ar' ? 'name_ar' : 'name_en';

        return array_map(fn (array $currency) => [
            'code' => $currency['code'],
            'symbol' => $currency['symbol'],
            'name' => $currency[$name],
        ], config('marketplace.currencies'));
    }

    /**
     * List languages.
     *
     * For the language dropdown in the header (ENG, ARA, FR). The chosen `code` goes in the `Accept-Language` header.
     *
     * @return list<array{code: string, label: string, name: string}>
     *
     * @unauthenticated
     */
    public function languages(): array
    {
        return config('marketplace.languages');
    }
}
