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
        Schema::create('plans', function (Blueprint $table) {
            $table->id();
            $table->string('code', 40)->unique();
            // freelancer | client | influencer: which account type the plan is for (the tabs on the plans page).
            $table->string('audience', 20);
            $table->string('name_en');
            $table->string('name_ar');
            $table->unsignedBigInteger('price_cents')->default(0);
            $table->char('currency', 3)->default('USD');
            // monthly_offers (null = unlimited), fee_percent (replaces the site fee when lower), promotion_discount_percent
            $table->json('features');
            $table->boolean('is_popular')->default(false);
            $table->boolean('is_active')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index(['audience', 'is_active', 'sort_order']);
        });

        Schema::create('subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('plan_id')->constrained();
            // active | past_due (renewal failed, grace period) | cancelled (replaced) | expired
            $table->string('status', 20)->default('active');
            $table->boolean('auto_renew')->default(true);
            $table->timestamp('current_period_start');
            $table->timestamp('current_period_end');
            $table->timestamp('cancelled_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'status']);
            $table->index(['status', 'current_period_end']);
        });

        // Paid placement on the homepage sliders ("Premium Services").
        Schema::create('promotions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('kind', 20);
            $table->foreignId('project_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('days');
            $table->unsignedBigInteger('price_cents');
            $table->char('currency', 3);
            $table->timestamp('starts_at');
            $table->timestamp('ends_at');
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('promotions');
        Schema::dropIfExists('subscriptions');
        Schema::dropIfExists('plans');
    }
};
