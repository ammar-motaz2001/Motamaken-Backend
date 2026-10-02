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
        Schema::create('deposits', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('method', 30);
            $table->string('status', 20)->default('pending');
            $table->string('provider', 30);
            $table->string('provider_ref')->unique();
            $table->string('checkout_url')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->string('failure_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
        });

        Schema::create('withdrawals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->bigInteger('amount_cents');
            // Charged on top of the amount, held together with it until the request is answered.
            $table->bigInteger('fee_cents')->default(0);
            $table->char('currency', 3);
            $table->string('method', 30);
            // Bank account / wallet details, encrypted.
            $table->text('destination');
            $table->string('status', 20)->default('pending');
            // The bank transfer reference the admin enters when paying.
            $table->string('payout_reference')->nullable();
            $table->string('reject_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('withdrawals');
        Schema::dropIfExists('deposits');
    }
};
