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
            $table->string('suspension_reason')->nullable();
        });

        Schema::table('milestones', function (Blueprint $table) {
            // The part of a settled milestone that went back to the client (a refund, or the client's side of a split).
            $table->bigInteger('refunded_cents')->default(0);
        });

        // Identity verification: documents are private files, reviewed by an admin.
        Schema::create('kyc_submissions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('document_type', 30);
            $table->text('document_number');
            $table->string('full_name');
            $table->date('date_of_birth');
            $table->string('front_path');
            $table->string('back_path')->nullable();
            $table->string('selfie_path');
            $table->string('status', 20)->default('pending');
            $table->string('rejection_reason')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'created_at']);
            $table->index(['status', 'created_at']);
        });

        // A complaint about one milestone: the money stays frozen in escrow until it is settled.
        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('escrow_id')->constrained()->cascadeOnDelete();
            $table->foreignId('milestone_id')->constrained()->cascadeOnDelete();
            $table->foreignId('opened_by')->constrained('users');
            $table->foreignId('against_id')->constrained('users');
            $table->string('reason', 30);
            $table->text('description');
            $table->string('status', 20)->default('open');
            // What the milestone was before it was frozen, so a withdrawn dispute can put it back.
            $table->string('milestone_status_before', 20);
            $table->string('resolution', 30)->nullable();
            // Split resolutions: the share of the milestone that goes back to the client.
            $table->unsignedTinyInteger('client_percent')->nullable();
            $table->text('resolution_note')->nullable();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index('milestone_id');
        });

        // The case timeline: what the two sides write, what they upload, and what the system and the admins record.
        Schema::create('dispute_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('dispute_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            // message | system | admin_note (admin notes are hidden from the two sides)
            $table->string('kind', 20)->default('message');
            $table->text('body')->nullable();
            $table->string('attachment_path')->nullable();
            $table->string('attachment_name')->nullable();
            $table->string('attachment_mime', 100)->nullable();
            $table->unsignedInteger('attachment_size')->nullable();
            $table->timestamps();

            $table->index(['dispute_id', 'id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('dispute_messages');
        Schema::dropIfExists('disputes');
        Schema::dropIfExists('kyc_submissions');
        Schema::table('milestones', fn (Blueprint $table) => $table->dropColumn('refunded_cents'));
        Schema::table('users', fn (Blueprint $table) => $table->dropColumn('suspension_reason'));
    }
};
