<?php

namespace App\Services\Payments;

use App\Models\Setting;

class FeeCalculator
{
    /**
     * The platform's cut of an amount released to freelancers, rounded to the nearest cent.
     */
    public function forRelease(int $cents, ?float $percent = null): int
    {
        $basisPoints = (int) round(($percent ?? (float) Setting::get('fees.site_percent')) * 100);

        return intdiv($cents * $basisPoints + 5000, 10000);
    }
}
