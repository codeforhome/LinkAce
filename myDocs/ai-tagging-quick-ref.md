# Offline AI Tagging - Quick Reference

**Status:** Phase 1 artisan commands are implemented. Phase 2 web UI is implemented (basic) at `GET /ai-tagging`.

## Phase 1 Commands
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

## Implemented Files (Phase 1–2)
- `app/Console/Commands/ExportForAITagging.php`
- `app/Console/Commands/ImportAITags.php`
- `app/Console/Commands/GenerateAIPrompt.php`
- `app/Http/Controllers/App/AITaggingController.php`
- `resources/views/app/ai-tagging/index.blade.php`

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
- Import flow: upload → Preview → confirm checkbox → Apply
- Export page includes a default prompt text box you can copy/paste along with the exported data.
