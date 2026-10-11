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
        // The profile sections ("Add / edit information in profile"), each a short list edited as a whole.
        Schema::table('profiles', function (Blueprint $table) {
            $table->json('skills')->nullable();
            $table->json('languages')->nullable();
            $table->json('experiences')->nullable();
            $table->json('educations')->nullable();
            $table->json('certificates')->nullable();
            // Influencers: the social accounts they post on, with audience numbers.
            $table->json('channels')->nullable();
        });

        Schema::create('portfolio_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('profile_id')->constrained()->cascadeOnDelete();
            $table->string('title', 120);
            $table->text('description')->nullable();
            $table->string('image_path');
            $table->string('link', 500)->nullable();
            $table->unsignedSmallInteger('position')->default(0);
            $table->timestamps();

            $table->index(['profile_id', 'position']);
        });

        // Ratings left by both sides once a project is completed.
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained()->cascadeOnDelete();
            $table->foreignId('reviewer_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('reviewee_id')->constrained('users')->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->timestamps();

            $table->unique(['project_id', 'reviewer_id']);
            $table->index(['reviewee_id', 'created_at']);
        });

        // "Report content": a user flags a profile, project, portfolio item or review for the moderators.
        Schema::create('reports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('reporter_id')->constrained('users')->cascadeOnDelete();
            $table->string('target_type', 20);
            $table->unsignedBigInteger('target_id');
            $table->string('reason', 30);
            $table->text('details')->nullable();
            // open | actioned | dismissed
            $table->string('status', 12)->default('open');
            $table->text('resolution_note')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->unique(['reporter_id', 'target_type', 'target_id']);
            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('reports');
        Schema::dropIfExists('reviews');
        Schema::dropIfExists('portfolio_items');
        Schema::table('profiles', function (Blueprint $table) {
            $table->dropColumn(['skills', 'languages', 'experiences', 'educations', 'certificates', 'channels']);
        });
    }
};
