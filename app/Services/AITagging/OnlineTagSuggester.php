<?php

namespace App\Services\AITagging;

use App\Models\Link;
use Illuminate\Support\Collection;

/**
 * Builds an AI-tagging request for a batch of links, calls OpenRouter, and
 * returns the model's raw JSON text. The output is intentionally the same shape
 * that TagSuggestionParser already understands ([{"id":.., "tags":[..]}]), so
 * the existing parse/preview/apply pipeline can be reused unchanged.
 */
class OnlineTagSuggester
{
    public function __construct(
        private readonly OpenRouterClient $client,
        private readonly PromptTemplate $prompt,
    ) {
    }

    public function isEnabled(): bool
    {
        return $this->client->isConfigured();
    }

    /**
     * Suggest tags for a batch of links. Returns the model's JSON text (ready to
     * be parsed by TagSuggestionParser), or null on failure / empty input.
     *
     * @param Collection<int,Link> $links
     * @param array<int,string> $vocabulary Existing tag names to prefer/reuse (for re-tagging).
     * @param bool $existingOnly When true, restrict suggestions to $vocabulary only.
     * @param int|null $maxTags When set, instruct the model to pick at most this many tags per link.
     */
    public function suggestForLinks(
        Collection $links,
        ?string $model = null,
        array $vocabulary = [],
        bool $existingOnly = false,
        ?int $maxTags = null,
    ): ?string {
        if ($links->isEmpty()) {
            return null;
        }

        $payload = $links->map(fn(Link $link) => [
            'id' => $link->id,
            'url' => $link->url,
            'title' => $link->title,
            'description' => $link->description,
            'current_tags' => $link->tags->pluck('name')->values()->all(),
            'domain' => $link->domainOfURL(),
        ])->values()->all();

        $messages = [
            ['role' => 'system', 'content' => $this->prompt->systemPromptForApi($vocabulary, $existingOnly, $maxTags)],
            ['role' => 'user', 'content' => json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '[]'],
        ];

        $content = $this->client->chat($messages, $model);
        if ($content === null) {
            return null;
        }

        return $this->stripCodeFences($content);
    }

    /**
     * Models sometimes wrap JSON in ```json ... ``` fences despite instructions.
     */
    private function stripCodeFences(string $content): string
    {
        $trimmed = trim($content);
        if (preg_match('/```(?:json)?\s*(.+?)\s*```/s', $trimmed, $m) === 1) {
            return trim($m[1]);
        }

        return $trimmed;
    }
}
