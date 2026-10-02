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
        // "project" ads from clients and "influencer_request" ads share one table: same card, same filters.
        Schema::create('projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('type', 20)->default('project');
            $table->foreignId('category_id')->constrained();
            $table->string('title');
            $table->text('description');
            $table->unsignedInteger('budget_min');
            $table->unsignedInteger('budget_max');
            $table->char('currency', 3)->default('USD');
            $table->foreignId('country_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('city_id')->nullable()->constrained()->nullOnDelete();
            $table->string('status', 20)->default('open');
            // "Distinctive projects" slider on the homepage.
            $table->boolean('is_featured')->default(false);
            $table->timestamp('featured_until')->nullable();
            $table->timestamps();

            $table->index(['type', 'status', 'created_at']);
            $table->index(['category_id', 'status', 'created_at']);
            $table->index('is_featured');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('projects');
    }
};
