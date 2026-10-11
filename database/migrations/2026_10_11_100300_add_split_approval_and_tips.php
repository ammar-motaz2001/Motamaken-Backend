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
        // Team escrows: every member confirms their share before any milestone is released ("split approval").
        Schema::table('escrow_payees', function (Blueprint $table) {
            // pending | approved | objected. A solo freelancer is approved from the start.
            $table->string('split_status', 10)->default('approved');
            $table->text('split_note')->nullable();
            $table->timestamp('split_answered_at')->nullable();
        });

        // A thank-you on top of the agreed price, paid from the client's wallet once work was released.
        Schema::create('tips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('client_id')->constrained('users');
            $table->unsignedBigInteger('amount_cents');
            $table->char('currency', 3);
            $table->string('note', 500)->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('escrow_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tips');
        Schema::table('escrow_payees', function (Blueprint $table) {
            $table->dropColumn(['split_status', 'split_note', 'split_answered_at']);
        });
    }
};
