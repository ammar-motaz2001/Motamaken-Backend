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
        // Payment protection for one project: the client pays milestone by milestone into escrow and releases it as work is approved.
        Schema::create('escrows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained();
            $table->foreignId('client_id')->constrained('users');
            $table->char('currency', 3);
            $table->bigInteger('total_cents');
            $table->string('status', 20)->default('awaiting_funding');
            $table->timestamps();

            $table->index('client_id');
        });

        // Who gets paid, and in what share (basis points: 10000 = 100%). One row for a solo freelancer, several for a team.
        Schema::create('escrow_payees', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained();
            $table->unsignedSmallInteger('share_bp');
            $table->boolean('is_lead')->default(false);

            $table->unique(['escrow_id', 'user_id']);
            $table->index('user_id');
        });

        Schema::create('milestones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('position');
            $table->string('title');
            $table->bigInteger('amount_cents');
            $table->string('status', 20)->default('pending');
            $table->date('due_date')->nullable();
            $table->bigInteger('fee_cents')->default(0);
            $table->text('submission_note')->nullable();
            $table->text('changes_note')->nullable();
            $table->timestamp('funded_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();

            $table->index(['escrow_id', 'position']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('milestones');
        Schema::dropIfExists('escrow_payees');
        Schema::dropIfExists('escrows');
    }
};
