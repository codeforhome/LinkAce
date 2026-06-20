# Link Metadata Resolution

How LinkAce fills a bookmark's **title / description / thumbnail** when you add a link —
including reliable handling for Twitter/X and scrape-resistant / JS-heavy pages.

## TL;DR
- Adding a link auto-fetches metadata via a **provider chain** in `app/Helper/HtmlMeta.php`.
- Twitter/X tweets → **FxTwitter** (free, no key). Most sites → the standard fetch.
- If a page returns nothing usable (empty/hostname title, or a bot-wall like Cloudflare's
  "just a moment…") → **Jina Reader** fallback (free, no key) renders the real page.
- Works out of the box. No configuration required.

---

## The provider chain

In order, `HtmlMeta::getFromUrl()` does:

1. **FxTwitter** (`app/Services/Metadata/FxTwitterProvider.php`)
   Only for tweet/status URLs (`x.com|twitter.com/<user>/status/<id>`). Returns author as
   title, tweet text as description, first photo/video thumbnail. Twitter serves a login
   wall to plain fetches, so this is the only reliable keyless route.

2. **Standard fetch** (`kovah/laravel-html-meta` package)
   Normal `<title>` / `<meta>` / `og:` / `twitter:` extraction. Handles the large majority
   of sites (incl. public Facebook & Instagram, which serve `og:` tags).

3. **Jina Reader fallback** (`app/Services/Metadata/JinaReaderProvider.php`)
   Runs **only when the result is "weak"** (see below). Sends the URL to `r.jina.ai`, which
   renders the page and returns a clean title + content. Free; an optional `JINA_API_KEY`
   raises rate limits.

4. **Microlink** (optional, legacy — `HtmlMeta::getMetaFromMicrolink()`)
   Tried only if still weak **and** `MICROLINK_API_KEY` is set. Disabled by default.

**Safety:** if the standard fetch raises `DisallowedIpException` (private/loopback host, or a
hostname resolving to one), the chain returns the fallback immediately and **never** sends the
URL to Jina/Microlink — so internal URLs are not leaked to a third party.

### "Weak" detection (when the fallback fires)
`HtmlMeta::isWeakMeta()` considers metadata weak when **both**:
- the **title** is empty, equals the bare host, or matches a **bot-wall phrase**, AND
- the **description** is empty or also a bot-wall phrase.

Bot-wall phrases (`$junkPatterns`) are specific to avoid false positives — e.g. `just a moment`,
`please wait`, `attention required`, `you've been blocked`, `network security`,
`checking your browser`, `log in to continue`. (Bare "login" is intentionally **not** matched,
so a legitimate article titled "How to log in securely" is not misflagged.)

### Don't replace junk with junk
`HtmlMeta::isUsefulMeta()` guards the fallback: if the reader is itself blocked and returns its
own bot-wall message, the original result is kept rather than overwritten.

---

## Behaviour by site type (tested)

| Site type | Result | Path |
|-----------|--------|------|
| Twitter/X tweet | ✅ author + text + media | FxTwitter |
| Public Facebook / Instagram page | ✅ description (Instagram also good title) | Standard `og:` tags |
| Cloudflare / generic bot-walled / JS-heavy | ✅ usually | Standard → weak → Jina |
| Normal websites | ✅ | Standard fetch |
| **Reddit** | ❌ blocked even via Jina | Reddit blocks server-side fetchers incl. Jina |
| Login-walled / private content | ❌ (impossible without auth) | — |

> **Reddit is the known hard case.** It blocks Jina's fetcher too ("You've been blocked by
> network security"). The only ways past are authentication or a paid rendering service
> (Microlink with a key). This is inherent to Reddit, not a bug.

---

## Configuration (`config/services.php` + `.env`)

All optional — sensible defaults ship enabled.

```bash
# Twitter/X tweets (free, no key)
FXTWITTER_BASE_URL=https://api.fxtwitter.com
FXTWITTER_TIMEOUT=8

# General fallback for scrape-resistant pages (free, no key; key only raises limits)
JINA_READER_ENABLED=true
JINA_READER_BASE_URL=https://r.jina.ai
JINA_API_KEY=
JINA_READER_TIMEOUT=15

# Optional legacy fallback, off unless a key is set
MICROLINK_ENABLED=false
MICROLINK_API_KEY=
MICROLINK_BASE_URL=https://api.microlink.io
MICROLINK_TIMEOUT=8
```
Run `php artisan config:clear` after editing `.env`.

To **disable** the remote reader entirely (fully offline metadata): `JINA_READER_ENABLED=false`.

---

## Where it runs
- **On save:** `LinkRepository::store()` and `ImportLinkJob` call `HtmlMeta::getFromUrl()` to
  fill any missing title/description.
- **Live preview:** the add/edit form posts to `fetch/meta-for-url`
  (`FetchController::metaFromUrl`, JS in `resources/assets/js/components/LinkMetaFetch.js`).

---

## Extending: add a specialized provider for another site
1. Create `app/Services/Metadata/<Name>Provider.php` with `handles(string $url): bool` and
   `fetch(string $url): ?array` (return keys `title`, `description`, `og:image`/`twitter:image`).
   Use the **`Http` facade** (not Guzzle) so it's fakeable in tests.
2. Wire it into the chain near the top of `HtmlMeta::getFromUrl()` (mirror FxTwitter).
3. Add a config block in `config/services.php` + env keys if it needs a base URL/key.
4. Add a provider unit test (`Http::fake(['host/*' => ...])`) like `tests/Helper/MetadataProvidersTest.php`.

Candidate open proxies for other platforms: `ddinstagram`/`instafix` (Instagram),
`vxtiktok` (TikTok). Add only when a site actually misbehaves.

---

## Tests
- `tests/Helper/MetadataProvidersTest.php` — FxTwitter + Jina parsing in isolation.
- `tests/Helper/HtmlMetaHelperTest.php` — full chain: tweet path, weak→Jina, bot-wall→Jina,
  junk-with-description is NOT weak, fallback-not-applied-when-reader-blocked, private-IP guard.
- `tests/Controller/FetchControllerTest.php` — the `meta-for-url` endpoint (regression guard).

Run: `php artisan test --testsuite=Helper` and `php artisan test --filter=FetchControllerTest`.
