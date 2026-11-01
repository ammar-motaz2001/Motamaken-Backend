<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Guarantee policy: a released milestone's payout can be held for some days. The days are fixed when the work is
 * delivered, so changing the policy only affects work delivered afterwards.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->unsignedSmallInteger('guarantee_days')->nullable()->after('released_at');
            $table->timestamp('held_until')->nullable()->index()->after('guarantee_days');
            $table->timestamp('hold_released_at')->nullable()->after('held_until');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn(['guarantee_days', 'held_until', 'hold_released_at']);
        });
    }
};
