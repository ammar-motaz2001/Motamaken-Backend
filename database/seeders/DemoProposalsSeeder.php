<?php

namespace Database\Seeders;

use App\Models\Country;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\User;
use App\Services\ProposalService;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Cache;

/**
 * Offers for the demo account: some on its own projects, and some it sent (one accepted, one declined, one pending).
 * Skips itself in production, and when offers already exist.
 */
class DemoProposalsSeeder extends Seeder
{
    public function run(): void
    {
        $demo = User::firstWhere('email', 'demo@motamakin.com');

        if (app()->isProduction() || ! $demo || Proposal::exists()) {
            return;
        }

        // Offers need a profile.
        $demo->profile()->firstOrCreate([], [
            'type' => 'freelancer',
            'job_title' => 'Product Designer',
            'bio' => 'Designs websites and brand identities.',
            'rating' => 4.5,
            'reviews_count' => 12,
            'country_id' => Country::firstWhere('code', 'EG')?->id,
        ]);

        $service = app(ProposalService::class);
        $people = User::whereIn('username', ['hosaam', 'mostafa', 'omar', 'sara', 'layla', 'youssef'])->get()->keyBy('username');
        $firstProject = fn (User $owner) => Project::where('user_id', $owner->id)->where('type', 'project')->orderBy('id')->first();

        // Offers received on the demo account's projects.
        $received = [['hosaam', 'mostafa', 'omar'], ['sara', 'layla'], ['youssef']];

        Project::where('user_id', $demo->id)->where('type', 'project')->orderBy('id')->take(3)->get()
            ->each(function (Project $project, int $index) use ($service, $people, $received) {
                foreach ($received[$index] as $step => $username) {
                    $service->submit($project, $people[$username], [
                        'amount' => $project->budget_min + 40 * ($step + 1),
                        'deliveryDays' => 5 + 3 * $step,
                        'message' => 'I have done several similar projects and can start right away. I will share drafts along the way.',
                    ]);
                }
            });

        // Offers the demo account sent: hosaam accepts, mostafa declines, omar has not answered.
        $sent = collect(['hosaam', 'mostafa', 'omar'])->map(fn (string $username) => [
            $people[$username],
            $service->submit($firstProject($people[$username]), $demo, [
                'amount' => 250,
                'deliveryDays' => 10,
                'message' => 'I would love to work on this. Here is my plan: research, three concepts, then refinements.',
            ]),
        ]);

        $service->accept($sent[0][1]->load('project.user', 'user'));
        $service->decline($sent[1][1]->load('project.user', 'user'));

        Cache::flush();
    }
}
