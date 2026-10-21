<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Requests to change an accepted offer (new price and/or delivery time) or to cancel the agreement, made by one side
 * of the project and answered by the other.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('agreement_changes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('proposal_id')->constrained()->cascadeOnDelete();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            // change | cancel
            $table->string('type', 10);
            // The new terms of a change; null keeps the current one.
            $table->decimal('amount', 12, 2)->nullable();
            $table->unsignedSmallInteger('delivery_days')->nullable();
            $table->text('reason');
            // pending | accepted | declined | withdrawn
            $table->string('status', 12)->default('pending');
            $table->foreignId('responded_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('response_note')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->index(['project_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('agreement_changes');
    }
};
