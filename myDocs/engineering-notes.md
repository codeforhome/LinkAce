# Engineering Notes & Future Reference

Cross-cutting notes for the local customizations on the `feature/ai-tagging-workflow`
branch (a fork on top of upstream `Kovah/LinkAce` 2.x). Read this before touching the
custom features or syncing upstream again.

## Feature map (what we added on top of upstream)

| Area | Docs | Key code |
|------|------|----------|
| Offline AI tagging | `ai-tagging-quick-ref.md`, `offline-ai-tagging-solution.md` | `ExportForAITagging`, `ImportAITags`, `GenerateAIPrompt` |
| Online AI tagging (OpenRouter/DeepSeek) | `ai-tagging-quick-ref.md` | `SuggestAITags`, `Services/AITagging/*` |
| Canonical tags + per-link cap + raw_tags | `ai-tagging-quick-ref.md`, `ai-tagging-improve.md` | `ManageCanonicalTags`, `TagApplier` |
| Link metadata (Twitter/X, bot-walls) | `link-metadata.md` | `Helper/HtmlMeta`, `Services/Metadata/*` |
| Tagging strategy / direction | `ai-tagging-improve.md` | — |

**Parked (deliberately not built):** semantic search (needs vectors on MariaDB + an
embeddings provider — too heavy for personal scale; see `ai-tagging-improve.md`), and the
enterprise tag-governance stack (pending-queue UI, scheduled governance, tag hierarchy).

## Schema added by this branch
- `tags.is_canonical` (bool) — the small browsing vocabulary. **No index** (deliberate: tiny
  table, and an index broke SQLite column-drop on rollback in tests).
- `links.ai_tagged_at` (datetime, audit-excluded) — powers `--skip-ai-tagged` incremental runs.
- `links.raw_tags` (json/array, audit-excluded) — full AI suggestion kept as a re-derivable
  layer; the `link_tags` pivot is the applied/capped set.

## Environment variables catalog (all optional)
```bash
# Online AI tagging (disabled by default — sends link data to OpenRouter)
OPENROUTER_ENABLED, OPENROUTER_API_KEY, OPENROUTER_MODEL (deepseek/deepseek-chat-v3-0324),
OPENROUTER_BASE_URL, OPENROUTER_TIMEOUT, OPENROUTER_VOCAB_LIMIT (300),
OPENROUTER_CANONICAL_MAX_TAGS (3)

# Link metadata (enabled by default, keyless)
FXTWITTER_BASE_URL, FXTWITTER_TIMEOUT
JINA_READER_ENABLED (true), JINA_READER_BASE_URL, JINA_API_KEY, JINA_READER_TIMEOUT
MICROLINK_ENABLED (false), MICROLINK_API_KEY, MICROLINK_BASE_URL, MICROLINK_TIMEOUT

# Infra / deploy
TRUSTED_HOSTS  (must list your domain or you get 400 Bad Request in non-local env)
TRUSTED_PROXIES
```
Documented in `.env.example` and `.env.dev`. The live `.env` holds real secrets and is
gitignored — never commit it. After editing `.env`: `php artisan config:clear`.

## Known gotchas (bit us during development)

1. **`TRUSTED_HOSTS` → 400 Bad Request.** Upstream added the `TrustHosts` middleware. In any
   non-`local` `APP_ENV`, requests whose Host isn't trusted are rejected with 400. Set
   `APP_URL` and `TRUSTED_HOSTS` (e.g. `linkace.test`) and `config:clear`.

2. **Stale phpunit bootstrap cache after composer changes.** After the Mix→Vite/dependency
   merge, tests failed at bootstrap with `Class "Barryvdh\Debugbar\ServiceProvider" not found`.
   Fix: `php artisan optimize:clear && composer dump-autoload` and remove the testing-env
   discovery caches: `rm -f bootstrap/cache/packages.phpunit.php bootstrap/cache/services.phpunit.php`.

3. **Pre-existing test failure (not ours): `Tests\Controller\API\ListApiTest > show request`.**
   Fails even with our changes stashed — an upstream Lists-API response-shape change. Ignore
   when judging our work; fix separately if desired.

4. **Intelephense false-positive storm.** The IDE may flag "Undefined type
   `Illuminate\…`" across files when its index desyncs (often after composer changes). `php -l`
   and the test suite are the source of truth. Fix: VS Code → "Developer: Reload Window" or
   restart the Intelephense server.

5. **Merge regression pattern: deleted upstream classes still referenced.** The Mix→Vite merge
   deleted `app/Rules/NoPrivateIpRule.php`, but our customized `FetchController` still
   referenced it → `fetch/meta-for-url` 500'd for every URL. After any upstream merge, grep our
   customized files for now-missing symbols. (Now covered by a regression test.)

6. **Vite, not Mix.** Build assets with `npm install && npm run build` (not `npm run dev`/Mix).
   `public/mix-manifest.json` is obsolete; output lives in `public/build/` (gitignored).

## HTTP client convention (testability)
New external integrations use the **Laravel `Http` facade**, which `Http::fake()` can intercept
and `Http::preventStrayRequests()` (set in `tests/TestCase.php`) guards. The older Microlink /
package code uses Guzzle directly and is **not** interceptable by `Http::fake`. Prefer the
facade for anything new.

## Test suites
PHPUnit suites are explicit directories in `phpunit.xml` (`tests/Commands`, `tests/Controller`,
`tests/Helper`, `tests/Models`, …). A new test dir must be added there to be discovered — reuse
an existing suite dir otherwise. Tests run on **SQLite** (be mindful of SQLite DDL limits, e.g.
dropping an indexed column on rollback).

```bash
php artisan test --testsuite=Helper
php artisan test --testsuite=Models
php artisan test --filter=FetchControllerTest
```

## Upstream sync checklist
1. `git fetch upstream && git merge upstream/2.x` (or merge into local `2.x` then this branch).
2. `composer install && npm install && npm run build`.
3. `php artisan migrate` and `php artisan optimize:clear`.
4. Grep customized files (`FetchController`, `HtmlMeta`, AI tagging services) for references to
   any symbols upstream may have removed/renamed.
5. Run the touched suites (Helper, Models, Commands, Controller). Expect the known
   `ListApiTest > show request` failure until upstream/our side reconciles it.
