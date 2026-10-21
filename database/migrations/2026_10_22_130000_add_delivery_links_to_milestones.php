<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links handed in with a delivery: for influencer requests, the published posts (proof of publishing); optional
 * for other projects (a live site, a shared folder).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->json('delivery_links')->nullable()->after('submission_note');
        });
    }

    public function down(): void
    {
        Schema::table('milestones', function (Blueprint $table) {
            $table->dropColumn('delivery_links');
        });
    }
};
