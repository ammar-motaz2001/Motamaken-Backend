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
        // Every place money can sit: a user's wallet, or a platform account (escrow, revenue, the outside world...).
        Schema::create('accounts', function (Blueprint $table) {
            $table->id();
            $table->string('type', 30);
            // "u:<id>" for a user's account, "system" for the platform's: lets one unique index cover both.
            $table->string('owner_key', 30);
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->char('currency', 3);
            // The sum of this account's entries, kept in step by the ledger. Whole cents.
            $table->bigInteger('balance_cents')->default(0);
            $table->timestamps();

            $table->unique(['type', 'owner_key', 'currency']);
            $table->index('user_id');
        });

        // A movement of money: its entries always add up to zero.
        Schema::create('ledger_transactions', function (Blueprint $table) {
            $table->id();
            $table->string('type', 40);
            $table->string('description')->nullable();
            // What it was for (a deposit, a milestone, ...).
            $table->nullableMorphs('reference');
            $table->json('meta')->nullable();
            // Posting the same key twice does nothing the second time.
            $table->string('idempotency_key')->nullable()->unique();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['type', 'created_at']);
        });

        Schema::create('ledger_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('transaction_id')->constrained('ledger_transactions')->cascadeOnDelete();
            $table->foreignId('account_id')->constrained();
            // Positive adds to the account, negative takes from it.
            $table->bigInteger('amount_cents');
            $table->bigInteger('balance_after_cents');
            $table->timestamp('created_at')->useCurrent();

            $table->index(['account_id', 'id']);
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key', 80)->primary();
            $table->json('value');
            $table->timestamp('updated_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('settings');
        Schema::dropIfExists('ledger_entries');
        Schema::dropIfExists('ledger_transactions');
        Schema::dropIfExists('accounts');
    }
};
