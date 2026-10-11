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
            // Two-factor authentication: every login also needs a code sent by `email` or `phone` to this target.
            $table->string('two_factor_method', 10)->nullable();
            $table->text('two_factor_target')->nullable();
            $table->timestamp('two_factor_enabled_at')->nullable();
            // Which notification groups are also sent by email (the bell always gets everything).
            $table->json('notification_settings')->nullable();
            $table->timestamp('password_changed_at')->nullable();
        });

        // "Login devices": where each session (Sanctum token) was opened.
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable();
            $table->string('user_agent', 255)->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('personal_access_tokens', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'user_agent']);
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['two_factor_method', 'two_factor_target', 'two_factor_enabled_at', 'notification_settings', 'password_changed_at']);
        });
    }
};
