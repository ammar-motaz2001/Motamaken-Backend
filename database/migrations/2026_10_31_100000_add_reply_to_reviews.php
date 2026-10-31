<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Only the client rates: the freelancer (or team lead) answers the client's review with a reply instead of rating the
 * client. Existing freelancer reviews become the reply on the client's review of the same project.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->text('reply')->nullable()->after('comment');
            $table->timestamp('replied_at')->nullable()->after('reply');
        });

        $workerReviews = DB::table('reviews')
            ->join('projects', 'projects.id', '=', 'reviews.project_id')
            ->whereColumn('reviews.reviewer_id', '!=', 'projects.user_id')
            ->select('reviews.*', 'projects.user_id as owner_id')
            ->get();

        foreach ($workerReviews as $review) {
            $clientReview = DB::table('reviews')->where('project_id', $review->project_id)->where('reviewer_id', $review->owner_id)->first();
            if ($clientReview && $review->comment && ! $clientReview->reply) {
                DB::table('reviews')->where('id', $clientReview->id)->update(['reply' => $review->comment, 'replied_at' => $review->created_at]);
            }
            DB::table('reviews')->where('id', $review->id)->delete();
        }

        // The clients lost those ratings: recount their profile stats.
        foreach ($workerReviews->pluck('reviewee_id')->unique() as $userId) {
            $stats = DB::table('reviews')->where('reviewee_id', $userId)->selectRaw('avg(rating) as average, count(*) as total')->first();
            DB::table('profiles')->where('user_id', $userId)->update(['rating' => round((float) $stats->average, 1), 'reviews_count' => (int) $stats->total]);
        }
    }

    public function down(): void
    {
        Schema::table('reviews', function (Blueprint $table) {
            $table->dropColumn(['reply', 'replied_at']);
        });
    }
};
