<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\UpdateSettingsRequest;
use App\Models\Setting;
use Dedoc\Scramble\Attributes\Group;

#[Group('Admin', 'The admin panel. Every call needs an administrator account.', weight: 99)]
class AdminSettingsController extends Controller
{
    private const MAP = [
        'siteFeePercent' => 'fees.site_percent',
        'depositMin' => 'deposits.min',
        'depositMax' => 'deposits.max',
        'withdrawalMin' => 'withdrawals.min',
        'withdrawalFee' => 'withdrawals.fee',
        'withdrawalRequiresKyc' => 'withdrawals.require_kyc',
    ];

    /**
     * Show settings.
     *
     * @return array{data: array{siteFeePercent: float, depositMin: float, depositMax: float, withdrawalMin: float, withdrawalFee: float, withdrawalRequiresKyc: bool}}
     */
    public function show(): array
    {
        return ['data' => $this->current()];
    }

    /**
     * Update settings.
     *
     * Send only what changes. Takes effect immediately for new movements; past ones are never recalculated.
     *
     * @return array{data: array{siteFeePercent: float, depositMin: float, depositMax: float, withdrawalMin: float, withdrawalFee: float, withdrawalRequiresKyc: bool}}
     */
    public function update(UpdateSettingsRequest $request): array
    {
        // The finance settings history: the audit log keeps the values before each change.
        request()->attributes->set('audit.before', $this->current());

        foreach (self::MAP as $input => $key) {
            if ($request->has($input)) {
                Setting::put($key, $request->input($input));
            }
        }

        return ['data' => $this->current()];
    }

    /**
     * The current fee, deposit and withdrawal settings.
     *
     * @return array<string, mixed>
     */
    private function current(): array
    {
        return [
            'siteFeePercent' => (float) Setting::get('fees.site_percent'),
            'depositMin' => (float) Setting::get('deposits.min'),
            'depositMax' => (float) Setting::get('deposits.max'),
            'withdrawalMin' => (float) Setting::get('withdrawals.min'),
            'withdrawalFee' => (float) Setting::get('withdrawals.fee'),
            'withdrawalRequiresKyc' => (bool) Setting::get('withdrawals.require_kyc'),
        ];
    }
}
