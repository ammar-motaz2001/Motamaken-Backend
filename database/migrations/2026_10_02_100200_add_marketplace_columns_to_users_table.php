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
            $table->string('avatar_path')->nullable();
            // Header preferences: language (ENG/ARA/FR), currency and dark mode toggles.
            $table->string('locale', 5)->nullable();
            $table->char('currency', 3)->default('USD');
            $table->string('theme', 10)->default('light');
            $table->decimal('wallet_balance', 12, 2)->default(0);
            $table->timestamp('last_seen_at')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['avatar_path', 'locale', 'currency', 'theme', 'wallet_balance', 'last_seen_at']);
        });
    }
};
