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
        // An offer ("Add Your Offer") from a freelancer or influencer on a project.
        Schema::create('proposals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            // Copied from the project when the offer is made.
            $table->char('currency', 3);
            $table->unsignedSmallInteger('delivery_days');
            $table->text('message');
            $table->string('status', 20)->default('pending');
            $table->timestamps();

            // One offer per person per project (they edit it instead of sending another).
            $table->unique(['project_id', 'user_id']);
            $table->index(['user_id', 'status', 'created_at']);
            $table->index(['project_id', 'status', 'created_at']);
        });

        Schema::table('projects', function (Blueprint $table) {
            // The offer the owner accepted. No foreign key: proposals point back to projects already.
            $table->unsignedBigInteger('accepted_proposal_id')->nullable()->after('status');
            $table->index(['user_id', 'status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('projects', function (Blueprint $table) {
            $table->dropIndex(['user_id', 'status', 'created_at']);
            $table->dropColumn('accepted_proposal_id');
        });

        Schema::dropIfExists('proposals');
    }
};
