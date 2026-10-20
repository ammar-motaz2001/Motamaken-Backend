<?php

namespace App\Services\Ai;

use App\Models\Profile;
use App\Models\Project;
use App\Support\Paged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Matching by meaning with pgvector: each project and profile is stored as an embedding, and the profiles closest to a
 * project are its best candidates. Empty when the AI or pgvector is not available.
 */
class Matcher
{
    public function __construct(private readonly Ollama $ollama) {}

    public static function available(): bool
    {
        return config('ai.enabled') && Schema::hasTable('embeddings');
    }

    public static function projectText(Project $project): string
    {
        return "{$project->title}\n{$project->description}";
    }

    public static function profileText(Profile $profile): string
    {
        return trim(($profile->job_title ?? '')."\n".implode(', ', $profile->skills ?? [])."\n".($profile->bio ?? ''));
    }

    /**
     * Store the embedding of a project or profile.
     */
    public function store(string $type, int $id, string $text): bool
    {
        if (! self::available() || ($vector = $this->ollama->embed($text)) === null) {
            return false;
        }

        DB::statement('insert into embeddings (subject_type, subject_id, model, embedding, updated_at) values (?, ?, ?, ?::vector, now())
            on conflict (subject_type, subject_id) do update set model = excluded.model, embedding = excluded.embedding, updated_at = now()',
            [$type, $id, config('ai.embedding_model'), '['.implode(',', $vector).']']);

        return true;
    }

    /**
     * The profiles closest to a project (freelancers for projects, influencers for influencer requests).
     *
     * @return list<array<string, mixed>>
     */
    public function forProject(Project $project, int $limit = 8): array
    {
        if (! self::available()) {
            return [];
        }

        try {
            $rows = DB::select("select p.subject_id, 1 - (p.embedding <=> q.embedding) as score
                from embeddings q join embeddings p on p.subject_type = 'profile' and vector_dims(p.embedding) = vector_dims(q.embedding)
                where q.subject_type = 'project' and q.subject_id = ?
                order by p.embedding <=> q.embedding limit ?", [$project->id, $limit * 3]);
        } catch (Throwable) {
            return [];
        }

        $scores = collect($rows)->pluck('score', 'subject_id');
        $type = $project->type === Project::TYPE_INFLUENCER_REQUEST ? 'influencer' : 'freelancer';

        return Profile::with('user')->whereIn('id', $scores->keys())->where('type', $type)
            ->whereHas('user', fn ($q) => $q->whereNull('suspended_at')->where('id', '!=', $project->user_id))->get()
            ->sortByDesc(fn (Profile $p) => $scores[$p->id])->take($limit)
            ->map(fn (Profile $p) => [...Paged::user($p->user), 'profileId' => $p->id, 'jobTitle' => $p->job_title, 'rating' => (float) $p->rating,
                'score' => round((float) $scores[$p->id], 3)])->values()->all();
    }
}
