# AI Tagging - Quick Reference

**Status:** Two workflows are implemented:
- **Offline** (no data leaves the instance): export → paste into any AI → import. Artisan + web UI.
- **Online** (optional, opt-in): tags are generated automatically via OpenRouter (DeepSeek). Artisan + web UI.

Both share the same apply pipeline (preview → confirm → apply, merge/replace, dry-run).

> ⚠️ The online provider sends link data (url, title, description) to OpenRouter. It is **disabled by default**.

---

## Offline Commands
```bash
# Export bookmarks for AI processing
php artisan links:export-for-ai --format=json > bookmarks.json

# Export for a specific user (recommended for non-interactive usage)
php artisan links:export-for-ai --user-email=you@example.com --format=json > bookmarks.json

# Export only untagged bookmarks (recommended)
php artisan links:export-for-ai --format=json --untagged-only > untagged_bookmarks.json

# Export untagged bookmarks excluding broken links (optimal)
php artisan links:export-for-ai --format=json --untagged-only --exclude-broken > clean_untagged.json

# Export limited batch for testing
php artisan links:export-for-ai --format=json --limit=50 --untagged-only > test_batch.json

# Save directly to file
php artisan links:export-for-ai --format=markdown --untagged-only --output=bookmarks.md

# Generate AI prompt template
php artisan ai:generate-prompt --format=markdown

# Import AI tag suggestions (dry run)
php artisan ai:import-tags --dry-run ai_response.txt

# Import for a specific user (recommended for non-interactive usage)
php artisan ai:import-tags --user-email=you@example.com --dry-run ai_response.txt

# Import AI tag suggestions (apply changes)
php artisan ai:import-tags --merge ai_response.txt
```

---

## Online Commands (OpenRouter / DeepSeek)

### Setup (.env)
```bash
OPENROUTER_ENABLED=true
OPENROUTER_API_KEY=sk-or-...            # from https://openrouter.ai/keys
OPENROUTER_MODEL=deepseek/deepseek-chat-v3-0324
OPENROUTER_TIMEOUT=60
OPENROUTER_VOCAB_LIMIT=300              # max existing tags injected into the prompt
OPENROUTER_CANONICAL_MAX_TAGS=3        # default per-link cap in canonical-only mode
```
Run `php artisan config:clear` after editing `.env`.

### `ai:suggest-tags` — generate + apply online
```bash
# Tag untagged links (safe preview first)
php artisan ai:suggest-tags --user-email=you@example.com --untagged-only --limit=20 --dry-run

# Apply for real (drop --dry-run); merge is the default
php artisan ai:suggest-tags --user-email=you@example.com --untagged-only --limit=20
```

**Selection flags** (which links to process):
| Flag | Effect |
|------|--------|
| `--limit=N` | Cap number of links |
| `--untagged-only` | Only links with no tags |
| `--exclude-broken` | Skip broken links |
| `--from-id=N` / `--to-id=N` | Inclusive id range |
| `--created-after=DATE` / `--created-before=DATE` | Creation-date range (e.g. `2025-01-01`) |
| `--skip-ai-tagged` | Skip links already AI-tagged (incremental re-runs) |

**Tagging behaviour flags:**
| Flag | Effect |
|------|--------|
| `--with-vocabulary` | Inject your existing tags so the model reuses them (re-tagging) |
| `--existing-tags-only` | Restrict to existing tags; never create new ones |
| `--canonical-only` | Restrict to your **canonical** tags only (implies existing-tags-only; default cap 3) |
| `--max-tags=N` | Hard cap tags applied per link |
| `--batch=N` | Links per AI request (default 25) |
| `--model=SLUG` | Override the model for this run |
| `--merge` (default) / `--replace` | Add to vs replace existing tags |
| `--skip-existing` | Skip links that already have tags |
| `--no-create-tags` | Don't create unknown tags |

> Hard enforcement: `--existing-tags-only` / `--canonical-only` filter applied tags to the allowed
> set **server-side** — even if the model ignores the instruction or suggests an existing
> non-canonical tag, it is dropped.

### `tags:canonical` — curate the small browsing vocabulary
```bash
php artisan tags:canonical --user-email=you@example.com --add=investing,laravel,dev-tools
php artisan tags:canonical --user-email=you@example.com --remove=laravel
php artisan tags:canonical --user-email=you@example.com --list
```

---

## Recipes

```bash
# Re-tag OLD bookmarks with tags created later, reusing existing vocabulary, incrementally
php artisan ai:suggest-tags --user-email=you@example.com \
  --created-before=2025-01-01 --with-vocabulary --skip-ai-tagged --dry-run

# Strict retrofit: only apply your canonical browsing tags (max 3/link), invent nothing
php artisan ai:suggest-tags --user-email=you@example.com --canonical-only

# Process a specific id range
php artisan ai:suggest-tags --user-email=you@example.com --from-id=1 --to-id=500 --with-vocabulary
```

### Tracking columns
- `links.ai_tagged_at` — stamped when AI sets a link's tags; powers `--skip-ai-tagged`.
- `links.raw_tags` — the full AI suggestion (JSON), kept even when the applied set is capped/filtered.
  Canonical `link_tags` stays the applied layer; `raw_tags` is a re-derivable snapshot.

---

## Workflow (High Level)
1. Export a batch of links (ideally untagged + non-broken).
2. Paste the export into your local AI chat (or other offline tool) with the generated prompt.
3. Save the AI output to a file.
4. Import with `--dry-run`, review, then import for real.

## Key Features
- ✅ Offline processing (no external data sharing)
- ✅ Compatible with any AI chat interface
- ✅ Dry-run mode for safe testing
- ✅ Merge/replace tag options
- ✅ Multiple export formats (JSON, CSV, Markdown)
- ✅ AI-aware of existing tags (keeps good ones, suggests improvements)
- ✅ Auto-creates new tags when needed
- ✅ Intelligent tag suggestions based on content analysis
- ✅ Encourages reuse of exported `current_tags` for consistency

## Implementation Phases
1. **Phase 1:** Core export/import commands ✅
2. **Phase 2:** Web interface integration ✅ (basic)
3. **Phase 3:** Advanced features
4. **Phase 4:** AI model integration (optional)

## Implemented Files
Offline:
- `app/Console/Commands/ExportForAITagging.php`
- `app/Console/Commands/ImportAITags.php`
- `app/Console/Commands/GenerateAIPrompt.php`

Online + shared services:
- `app/Console/Commands/SuggestAITags.php` (`ai:suggest-tags`)
- `app/Console/Commands/ManageCanonicalTags.php` (`tags:canonical`)
- `app/Services/AITagging/OpenRouterClient.php` (chat-completions client)
- `app/Services/AITagging/OnlineTagSuggester.php` (builds payload/prompt)
- `app/Services/AITagging/TagApplier.php` (shared apply: merge/replace, max-tags, allow-list, raw_tags)
- `app/Services/AITagging/PromptTemplate.php` (offline + API prompts)
- `app/Services/AITagging/TagSuggestionParser.php` (parses model/file output)

Web + config:
- `app/Http/Controllers/App/AITaggingController.php`
- `resources/views/app/ai-tagging/index.blade.php`
- `config/services.php` (`openrouter` block)

Schema:
- `tags.is_canonical`, `links.ai_tagged_at`, `links.raw_tags`

## AI Tag Intelligence

**How AI Processes Tags:**
- **Context Aware**: AI sees existing tags and bookmark content
- **Smart Improvements**: Suggests better tag names (e.g., "javascript" vs "code")
- **New Tag Creation**: Automatically creates relevant tags that don't exist
- **Quality Focus**: Prioritizes specific, actionable tags over generic ones
- **Consistency Bias**: Prefer reusing the same tags across similar links; avoid one-off tags

**Example AI Behavior:**
- Input: Bookmark with tags ["web", "dev"]
- AI Analysis: Laravel documentation site
- Output: ["web", "dev", "laravel", "php", "framework", "documentation"]

## Expected AI Response (Recommended)
Keep the import format intentionally boring so it’s easy to parse and easy to eyeball in a diff.

- One link per line
- Prefer a stable identifier (e.g., LinkAce link `id`) plus URL (helps avoid ambiguity)
- Comma-separated tags

Example:
```text
123 https://laravel.com/docs: php, laravel, documentation, framework
124 https://github.com/org/repo: github, repo, development, opensource
```

Also accepted (common AI output styles):
```text
123 [https://laravel.com/docs](https://laravel.com/docs): php, laravel, documentation
123. https://laravel.com/docs: php, laravel, documentation
- 123 https://laravel.com/docs: php, laravel, documentation
```

## Safety Checklist
- [ ] Start with a small batch (`--limit=50`) to validate end-to-end behavior
- [ ] Use `--dry-run` first and review the proposed changes
- [ ] Prefer `--merge` unless you explicitly want to replace all tags
- [ ] Keep a backup / rollback plan before running the non-dry import

## Next Action
Use `GET /ai-tagging` for the end-to-end workflow, or run the Phase 1 artisan commands directly for scripting/automation.

## Web UI (Implemented)
- Page: `GET /ai-tagging`
- Menu links: profile-name dropdown → “AI Tag Export” / “AI Tag Import”
- **Offline import flow:** upload → Preview → confirm checkbox → Apply
- **Online auto-suggest card** (shown when OpenRouter is enabled): pick vocabulary mode
  (free / prefer mine / canonical-only), max tags, id range, created-date range, skip-ai-tagged
  → Preview → Apply (same preview screen as import).
- Export page includes a default prompt text box you can copy/paste along with the exported data.
