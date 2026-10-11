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
        // Every change to users.points, so the balance can be explained ("Points & gifts" history).
        Schema::create('point_transactions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->integer('amount');
            $table->string('reason', 40);
            // Makes an award happen at most once (e.g. "referral:42"); null for repeatable ones.
            $table->string('key', 80)->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'key']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('user_medals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->timestamp('awarded_at')->useCurrent();

            $table->unique(['user_id', 'code']);
        });

        // "Gifts — prize draw": members spend points on tickets; winners are drawn weighted by tickets.
        Schema::create('draws', function (Blueprint $table) {
            $table->id();
            $table->string('title_en');
            $table->string('title_ar');
            $table->unsignedInteger('ticket_cost');
            $table->unsignedInteger('max_tickets_per_user')->nullable();
            $table->timestamp('starts_at');
            $table->timestamp('draw_at');
            // open | drawn | cancelled
            $table->string('status', 10)->default('open');
            // [{rank, name_en, name_ar, kind: cash|item, value, currency}]
            $table->json('prizes');
            $table->timestamp('drawn_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'draw_at']);
        });

        Schema::create('draw_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('tickets');
            $table->timestamps();

            $table->unique(['draw_id', 'user_id']);
        });

        Schema::create('draw_winners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('draw_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('rank');
            $table->json('prize');
            // wallet (cash credited) | delivery (an item shipped to the address)
            $table->string('claim_method', 10)->nullable();
            $table->text('claim_details')->nullable();
            $table->timestamp('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(['draw_id', 'rank']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('draw_winners');
        Schema::dropIfExists('draw_entries');
        Schema::dropIfExists('draws');
        Schema::dropIfExists('user_medals');
        Schema::dropIfExists('point_transactions');
    }
};
