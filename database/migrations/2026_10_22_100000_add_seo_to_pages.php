<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Search-engine fields per information page, edited in the admin page editor: a title and description per language
 * (empty means "use the page title and the start of the text") and a switch to keep the page out of search results.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->string('meta_title_en', 70)->nullable()->after('body_ar');
            $table->string('meta_title_ar', 70)->nullable()->after('meta_title_en');
            $table->string('meta_description_en', 170)->nullable()->after('meta_title_ar');
            $table->string('meta_description_ar', 170)->nullable()->after('meta_description_en');
            $table->boolean('noindex')->default(false)->after('meta_description_ar');
        });
    }

    public function down(): void
    {
        Schema::table('pages', function (Blueprint $table) {
            $table->dropColumn(['meta_title_en', 'meta_title_ar', 'meta_description_en', 'meta_description_ar', 'noindex']);
        });
    }
};
