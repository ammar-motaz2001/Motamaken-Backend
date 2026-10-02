<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->string('referral_code', 12)->nullable()->unique();
            $table->foreignId('referred_by')->nullable()->constrained('users')->nullOnDelete();
        });

        // Everyone who signed up before gets a code too.
        DB::table('users')->whereNull('referral_code')->orderBy('id')->each(function ($user) {
            DB::table('users')->where('id', $user->id)->update(['referral_code' => Str::upper(Str::random(8))]);
        });

        // Visits to someone's invite link, one per visitor per day.
        Schema::create('affiliate_clicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('ip_hash', 64);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['user_id', 'ip_hash', 'created_at']);
        });

        // A share of the platform fee paid to the referrer when someone they invited earns money.
        Schema::create('affiliate_earnings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('referrer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('referred_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            // The referrer's tier rate when it was earned.
            $table->decimal('rate_percent', 5, 2);
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['referrer_id', 'referred_id', 'milestone_id']);
            $table->index(['referrer_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('affiliate_earnings');
        Schema::dropIfExists('affiliate_clicks');
        Schema::table('users', function (Blueprint $table) {
            $table->dropConstrainedForeignId('referred_by');
            $table->dropColumn('referral_code');
        });
    }
};
