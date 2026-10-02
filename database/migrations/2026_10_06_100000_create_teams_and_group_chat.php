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
        // A group of freelancers who take projects together and share the pay.
        Schema::create('teams', function (Blueprint $table) {
            $table->id();
            $table->foreignId('owner_id')->constrained('users')->cascadeOnDelete();
            $table->string('name', 80);
            $table->text('description')->nullable();
            // draft (being put together) | active (published, can send offers) | disbanded
            $table->string('status', 20)->default('draft');
            $table->timestamp('published_at')->nullable();
            $table->timestamps();

            $table->index(['owner_id', 'status']);
        });

        Schema::create('team_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('role_title', 80)->nullable();
            // The member's cut of every payment, in basis points (10000 = 100%). Accepting the invitation approves it.
            $table->unsignedSmallInteger('share_bp');
            // invited | accepted | declined | expired
            $table->string('status', 20)->default('invited');
            $table->boolean('is_lead')->default(false);
            $table->timestamp('invited_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('responded_at')->nullable();
            $table->timestamps();

            $table->unique(['team_id', 'user_id']);
            $table->index(['user_id', 'status']);
            $table->index(['status', 'expires_at']);
        });

        Schema::table('proposals', function (Blueprint $table) {
            // Set when the offer is made on behalf of a team: the team's members are paid, not just the sender.
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
        });

        Schema::table('conversations', function (Blueprint $table) {
            $table->string('type', 10)->default('direct');
            $table->string('title')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            // Groups have no pair of users to key on.
            $table->string('pair_key')->nullable()->change();
        });

        Schema::table('conversation_user', function (Blueprint $table) {
            // Group chats track what each member has read; direct chats use messages.read_at.
            $table->unsignedBigInteger('last_read_message_id')->nullable();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('conversation_user', fn (Blueprint $table) => $table->dropColumn('last_read_message_id'));
        Schema::table('conversations', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by');
            $table->dropColumn(['type', 'title']);
        });
        Schema::table('proposals', fn (Blueprint $table) => $table->dropConstrainedForeignId('team_id'));
        Schema::dropIfExists('team_members');
        Schema::dropIfExists('teams');
    }
};
