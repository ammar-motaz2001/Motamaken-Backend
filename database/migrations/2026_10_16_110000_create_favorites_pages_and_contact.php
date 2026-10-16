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
        // The heart icon: projects and freelancer/influencer profiles a user saved.
        Schema::create('favorites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            // project | profile
            $table->string('type', 20);
            $table->unsignedBigInteger('target_id');
            $table->timestamp('created_at')->useCurrent();

            $table->unique(['user_id', 'type', 'target_id']);
            $table->index(['type', 'target_id']);
        });

        // The footer's information pages (terms, privacy, guide, fees...), written and edited by admins in both languages.
        Schema::create('pages', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 60)->unique();
            // legal | help | about: how the footer and the sitemap group them.
            $table->string('section', 20);
            $table->string('title_en');
            $table->string('title_ar');
            // Markdown. `{{site_fee}}` style placeholders are filled with the live settings when read.
            $table->longText('body_en');
            $table->longText('body_ar');
            $table->boolean('is_published')->default(true);
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->foreignId('updated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['section', 'sort_order']);
        });

        // The contact form: open to visitors, read by admins.
        Schema::create('contact_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name', 100);
            $table->string('email');
            $table->string('topic', 30);
            $table->string('subject', 150);
            $table->text('message');
            // new | handled
            $table->string('status', 20)->default('new');
            $table->text('admin_note')->nullable();
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('handled_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contact_messages');
        Schema::dropIfExists('pages');
        Schema::dropIfExists('favorites');
    }
};
