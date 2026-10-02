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
        // Project categories ("Website, IT & Software", ...) and influencer platforms ("Instagram", ...).
        Schema::create('categories', function (Blueprint $table) {
            $table->id();
            $table->string('type', 20);
            $table->string('slug', 60);
            $table->string('name_en');
            $table->string('name_ar');
            $table->string('icon', 60)->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);

            $table->unique(['type', 'slug']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('categories');
    }
};
