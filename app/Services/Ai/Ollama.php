<?php

namespace App\Services\Ai;

use App\Models\IntegrationLog;
use Illuminate\Support\Facades\Http;
use Throwable;

/**
 * A thin client for a self-hosted Ollama server. Every method returns null on failure (and logs it) instead of throwing:
 * the AI features are extras and must never break the work that triggered them.
 */
class Ollama
{
    /**
     * Whether the Ollama server answers within 3 seconds.
     */
    public function ping(): bool
    {
        try {
            return Http::baseUrl(config('ai.url'))->timeout(3)->get('/api/tags')->successful();
        } catch (Throwable) {
            return false;
        }
    }

    /**
     * Ask the chat model for a JSON answer.
     *
     * @return array<string, mixed>|null
     */
    public function json(string $system, string $prompt): ?array
    {
        try {
            $response = Http::baseUrl(config('ai.url'))->timeout(config('ai.timeout'))->post('/api/generate', [
                'model' => config('ai.model'),
                'system' => $system,
                'prompt' => $prompt,
                'format' => 'json',
                'stream' => false,
                'options' => ['temperature' => 0],
            ]);

            if (! $response->successful()) {
                IntegrationLog::record('ai', "Ollama answered {$response->status()}", ['model' => config('ai.model')]);

                return null;
            }

            $answer = json_decode((string) $response->json('response'), true);

            return is_array($answer) ? $answer : null;
        } catch (Throwable $e) {
            IntegrationLog::record('ai', "Ollama unreachable: {$e->getMessage()}");

            return null;
        }
    }

    /**
     * The embedding vector of a text (cut to 8000 characters), or null on failure.
     *
     * @return list<float>|null
     */
    public function embed(string $text): ?array
    {
        try {
            $response = Http::baseUrl(config('ai.url'))->timeout(config('ai.timeout'))->post('/api/embeddings', [
                'model' => config('ai.embedding_model'),
                'prompt' => mb_substr($text, 0, 8000),
            ]);
            $vector = $response->json('embedding');

            return $response->successful() && is_array($vector) && $vector !== [] ? array_map('floatval', $vector) : null;
        } catch (Throwable $e) {
            IntegrationLog::record('ai', "Ollama embeddings failed: {$e->getMessage()}");

            return null;
        }
    }
}
