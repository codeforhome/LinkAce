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
ID URL: tag1, tag2, tag3

Links:
PASTE EXPORT HERE
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
