<?php

namespace App\Jobs;

use App\Services\Ai\Matcher;
use App\Services\Ai\Moderator;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Background AI work on new or changed content: screening for abuse, and the embedding used for matching. Runs after the
 * user's request has finished; a slow or missing AI server only delays it.
 */
class ScreenContent implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable;

    public int $tries = 2;

    public int $backoff = 60;

    public function __construct(
        public readonly string $targetType,
        public readonly int $targetId,
        public readonly string $text,
        /** `project` or `profile` to also store the embedding, under `$embedId`. */
        public readonly ?string $embedAs = null,
        public readonly ?int $embedId = null,
    ) {}

    /**
     * Screen the text for abuse and, when asked, store its embedding for matching.
     */
    public function handle(Moderator $moderator, Matcher $matcher): void
    {
        $moderator->screen($this->targetType, $this->targetId, $this->text);

        if ($this->embedAs) {
            $matcher->store($this->embedAs, $this->embedId ?? $this->targetId, $this->text);
        }
    }

    /**
     * Queue the work if the AI features are on.
     */
    public static function forContent(string $targetType, int $targetId, string $text, ?string $embedAs = null, ?int $embedId = null): void
    {
        if (config('ai.enabled')) {
            static::dispatch($targetType, $targetId, $text, $embedAs, $embedId)->afterCommit();
        }
    }
}
