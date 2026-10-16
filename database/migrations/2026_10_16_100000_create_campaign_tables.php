<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // A paid promotion: an advertiser funds a budget, promoters share links, every valid visit pays the promoter.
        Schema::create('campaigns', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            // What the links lead to: one of the owner's projects, or the owner's profile. Never an outside address.
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->char('currency', 3);
            $table->unsignedBigInteger('budget_cents');
            // Paid out to promoters plus the platform's cut.
            $table->unsignedBigInteger('spent_cents')->default(0);
            // What one valid visit costs the advertiser. The promoter gets this minus the platform fee.
            $table->unsignedBigInteger('cost_per_visit_cents');
            $table->decimal('fee_percent', 5, 2);
            // draft | active | paused | completed (budget used up or ended) | cancelled
            $table->string('status', 20)->default('draft');
            // The visit filtering rules (the toggles on the filtering screen).
            $table->json('filters');
            $table->timestamp('starts_at')->nullable();
            $table->timestamp('ends_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['owner_id', 'created_at']);
        });

        // Someone promoting a campaign, with their own link code.
        Schema::create('campaign_promoters', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 16)->unique();
            $table->unsignedInteger('valid_visits')->default(0);
            $table->unsignedInteger('rejected_visits')->default(0);
            $table->unsignedBigInteger('earned_cents')->default(0);
            $table->timestamps();

            $table->unique(['campaign_id', 'user_id']);
            $table->index('user_id');
        });

        // Every visit through a link, paid or not: the rejected ones show on the filtering screen with the reason.
        Schema::create('campaign_visits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('campaign_id')->constrained()->cascadeOnDelete();
            $table->foreignId('promoter_id')->constrained('campaign_promoters')->cascadeOnDelete();
            // A hash of the visitor's IP: enough to spot repeats, without storing the address.
            $table->string('ip_hash', 64);
            $table->string('country', 2)->nullable();
            $table->string('referrer_host')->nullable();
            // valid | rejected
            $table->string('status', 10);
            // bot | duplicate | rate_limited | country | own_link | budget_exhausted | not_running
            $table->string('reject_reason', 20)->nullable();
            $table->unsignedBigInteger('reward_cents')->default(0);
            $table->timestamp('created_at')->useCurrent();

            $table->index(['campaign_id', 'created_at']);
            $table->index(['campaign_id', 'ip_hash', 'created_at']);
            $table->index(['promoter_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('campaign_visits');
        Schema::dropIfExists('campaign_promoters');
        Schema::dropIfExists('campaigns');
    }
};
