<?php

namespace App\Observers;

use App\Jobs\ScreenContent;
use App\Models\PortfolioItem;
use App\Models\Profile;
use App\Models\Project;
use App\Models\Proposal;
use App\Models\Review;
use App\Services\Ai\Matcher;
use Illuminate\Database\Eloquent\Model;

/**
 * Sends new and edited user content to the background AI screening (only when config('ai.enabled')).
 */
class AiScreeningObserver
{
    /** Model => [report target, fields whose change triggers a new screening]. */
    private const WATCH = [
        Project::class => ['project', ['title', 'description']],
        Proposal::class => ['proposal', ['message']],
        Review::class => ['review', ['comment']],
        Profile::class => ['user', ['job_title', 'bio', 'skills']],
        PortfolioItem::class => ['portfolio', ['title', 'description']],
    ];

    public function saved(Model $model): void
    {
        if (! config('ai.enabled')) {
            return;
        }

        [$target, $fields] = self::WATCH[$model::class];

        if (! $model->wasRecentlyCreated && ! $model->wasChanged($fields)) {
            return;
        }

        match (true) {
            $model instanceof Project => ScreenContent::forContent('project', $model->id, Matcher::projectText($model), 'project'),
            // Reported as the user; embedded under the profile id (the matcher returns profiles).
            $model instanceof Profile => ScreenContent::forContent('user', $model->user_id, Matcher::profileText($model), 'profile', $model->id),
            default => ScreenContent::forContent($target, $model->id, implode("\n", array_filter(array_map(fn ($f) => $model->{$f}, $fields)))),
        };
    }
}
