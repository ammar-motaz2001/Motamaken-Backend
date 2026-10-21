<?php

namespace App\Console\Commands;

use App\Models\Profile;
use App\Models\Project;
use App\Services\Ai\Matcher;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('ai:embed {--only= : projects or profiles}')]
#[Description('Store the embeddings of every open project and every profile, for AI matching (needs AI_ENABLED and pgvector)')]
class EmbedContent extends Command
{
    /**
     * Store the embeddings for open projects and/or profiles. Fails when AI matching is not available.
     */
    public function handle(Matcher $matcher): int
    {
        if (! Matcher::available()) {
            $this->error('AI matching is off: set AI_ENABLED=true and install pgvector.');

            return self::FAILURE;
        }

        $done = 0;
        if ($this->option('only') !== 'profiles') {
            foreach (Project::open()->lazyById() as $project) {
                $done += (int) $matcher->store('project', $project->id, Matcher::projectText($project));
            }
        }
        if ($this->option('only') !== 'projects') {
            foreach (Profile::lazyById() as $profile) {
                $done += (int) $matcher->store('profile', $profile->id, Matcher::profileText($profile));
            }
        }

        $this->info("Stored {$done} embeddings.");

        return self::SUCCESS;
    }
}
