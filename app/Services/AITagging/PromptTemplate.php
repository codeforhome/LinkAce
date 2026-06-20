<?php

namespace App\Services\AITagging;

class PromptTemplate
{
    public function renderText(): string
    {
        return <<<TEXT
You are helping me tag bookmarks/links.

Task:
- For each link below, suggest 3–8 relevant tags.
- Keep relevant existing tags.
- Improve overly generic tags (if needed) by suggesting better alternatives.

About existing tags in the input:
- Each link may include a `current_tags` / "Current tags" field. Treat those as the preferred tag vocabulary.
- Reuse existing tag words whenever they still apply. Only add missing tags.
- Do not rename/replace existing tags unless the tag is clearly wrong or misleading; if you propose a rename, keep the original tag too.

Consistency rules (important):
- Prefer reusing the same tag words across similar links (keep a consistent taxonomy).
- Do not invent lots of new one-off tags. Introduce at most 1–2 new tags per link unless absolutely necessary.
- If a good existing tag already applies, reuse it instead of creating a synonym (e.g., prefer "prompt-engineering" consistently over mixing "prompt engineering", "prompting", "prompts").

Guidelines:
- Prefer specific tags (e.g., "laravel" over "php", "orm" over "database" when applicable).
- Use consistent casing (lowercase is preferred).
- Avoid duplicates and near-duplicates ("js" vs "javascript")—pick one.
- Tags should be short (1–3 words) and useful for later search/filtering.
 - Prefer hyphenated tags for multi-word concepts (e.g., "prompt-engineering", "access-control").

Output format (one per line):
NUMBER URL: tag1, tag2, tag3

Links:
PASTE EXPORT HERE
TEXT;
    }

    /**
     * System prompt used for the online (OpenRouter) provider. Unlike renderText(),
     * the model receives the links as a JSON array in the user message and must
     * reply with strict JSON so it can be parsed by TagSuggestionParser.
     */
    public function systemPromptForApi(): string
    {
        return <<<TEXT
You are helping tag bookmarks/links. The user message is a JSON array of links.

For each link, suggest 3–8 relevant tags.
- Each link includes a `current_tags` array. Treat those as the preferred vocabulary:
  reuse them where they still apply, and only add missing tags.
- Prefer specific tags ("laravel" over "php", "orm" over "database" when applicable).
- Use lowercase. Prefer hyphenated tags for multi-word concepts ("prompt-engineering").
- Reuse consistent tag words across similar links. Introduce at most 1–2 new tags per link.
- Avoid near-duplicates ("js" vs "javascript")—pick one.

Output rules (critical):
- Return ONLY a JSON array. No prose, no explanations, no markdown code fences.
- Each element must be: {"id": <link id>, "tags": ["tag1", "tag2", ...]}
- Use the exact `id` value from the input for each link.
TEXT;
    }

    public function renderMarkdown(): string
    {
        $text = $this->renderText();

        return <<<MD
## Offline AI Tagging Prompt

```text
$text
```
MD;
    }
}
