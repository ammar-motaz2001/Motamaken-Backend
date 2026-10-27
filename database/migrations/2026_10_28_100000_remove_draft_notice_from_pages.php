<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * The legal pages were seeded with a "Draft — review before launch" notice; it is removed from the saved text.
     */
    public function up(): void
    {
        $notices = [
            'body_en' => "> **Draft.** This text is a starting point and must be reviewed before launch.\n\n",
            'body_ar' => "> **مسودة.** هذا النص نقطة بداية ويجب مراجعته قبل الإطلاق.\n\n",
        ];

        foreach (DB::table('pages')->get(['id', 'body_en', 'body_ar']) as $page) {
            DB::table('pages')->where('id', $page->id)->update([
                'body_en' => str_replace($notices['body_en'], '', (string) $page->body_en),
                'body_ar' => str_replace($notices['body_ar'], '', (string) $page->body_ar),
            ]);
        }
    }

    /**
     * Nothing to restore: the notice was placeholder text.
     */
    public function down(): void {}
};
