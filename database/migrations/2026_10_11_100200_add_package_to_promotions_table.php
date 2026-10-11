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
        Schema::table('promotions', function (Blueprint $table) {
            // The package bought from the "Feature your ..." popup; null when days were bought directly.
            $table->string('package', 20)->nullable()->after('kind');
            $table->unsignedSmallInteger('points_awarded')->default(0)->after('currency');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('promotions', function (Blueprint $table) {
            $table->dropColumn(['package', 'points_awarded']);
        });
    }
};
