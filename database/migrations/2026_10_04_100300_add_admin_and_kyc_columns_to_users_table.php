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
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('is_admin')->default(false);
            // Blocks every request of the user until lifted.
            $table->timestamp('suspended_at')->nullable();
            // none | pending | approved | rejected: the outcome of the identity check.
            $table->string('kyc_status', 20)->default('none');
            // Money now lives in the ledger, not in a column.
            $table->dropColumn('wallet_balance');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->decimal('wallet_balance', 12, 2)->default(0);
            $table->dropColumn(['is_admin', 'suspended_at', 'kyc_status']);
        });
    }
};
