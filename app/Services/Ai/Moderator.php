<?php

namespace App\Services\Ai;

use App\Models\Report;

/**
 * Screens user content with the AI model and files a report for the moderators when it looks like a problem. The model
 * only suggests: a person always decides.
 */
class Moderator
{
    private const SYSTEM = 'You are a content moderator for a freelance and influencer marketplace. Decide if the text is spam, '
        .'a scam or fraud (asking to pay or talk outside the platform, fake offers), hateful or sexual content, or contact '
        .'details shared to avoid platform fees. Answer only JSON: {"flagged": true|false, "reason": "spam"|"scam"|"inappropriate"|"other", '
        .'"confidence": 0.0-1.0, "explanation": "one short sentence"}.';

    public function __construct(private readonly Ollama $ollama) {}

    /**
     * @return Report|null The report filed, if any.
     */
    public function screen(string $targetType, int $targetId, string $text): ?Report
    {
        if (! config('ai.enabled') || mb_strlen(trim($text)) < 10) {
            return null;
        }

        $answer = $this->ollama->json(self::SYSTEM, mb_substr($text, 0, 6000));

        if (! ($answer['flagged'] ?? false) || (float) ($answer['confidence'] ?? 0) < config('ai.flag_threshold')) {
            return null;
        }

        // One automatic report per item.
        return Report::firstOrCreate(
            ['reporter_id' => null, 'target_type' => $targetType, 'target_id' => $targetId, 'source' => 'ai'],
            [
                'reason' => in_array($answer['reason'] ?? null, Report::REASONS, true) ? $answer['reason'] : 'other',
                'details' => mb_substr('AI: '.($answer['explanation'] ?? '').' ('.round((float) $answer['confidence'] * 100).'%)', 0, 2000),
                'priority' => ($answer['reason'] ?? null) === 'scam' ? 'high' : 'normal',
            ],
        );
    }
}
