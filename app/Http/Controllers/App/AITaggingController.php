<?php

namespace App\Http\Controllers\App;

use App\Http\Controllers\Controller;
use App\Models\Link;
use App\Models\Tag;
use App\Services\AITagging\OnlineTagSuggester;
use App\Services\AITagging\PromptTemplate;
use App\Services\AITagging\TagSuggestionParser;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

class AITaggingController extends Controller
{
    private const PREVIEW_SESSION_KEY = 'ai_tagging_preview';

    public function index(): View
    {
        return view('app.ai-tagging.index', [
            'pageTitle' => 'AI Tagging',
            'promptText' => app(PromptTemplate::class)->renderText(),
            'onlineEnabled' => app(OnlineTagSuggester::class)->isEnabled(),
            'onlineModel' => config('services.openrouter.model'),
        ]);
    }

    /**
     * Generate tag suggestions online via OpenRouter, then route the result into
     * the same preview -> confirm -> apply flow used by file imports.
     */
    public function suggest(Request $request): RedirectResponse
    {
        $suggester = app(OnlineTagSuggester::class);
        if (!$suggester->isEnabled()) {
            flash('Online AI suggestions are not enabled. Set OPENROUTER_ENABLED and OPENROUTER_API_KEY.', 'error');
            return redirect()->route('ai-tagging.index');
        }

        $validated = $request->validate([
            'limit' => ['nullable', 'integer', 'min:1', 'max:200'],
            'untagged_only' => ['nullable', 'boolean'],
            'exclude_broken' => ['nullable', 'boolean'],
            'apply_behavior' => ['nullable', 'in:merge,replace'],
            'skip_existing' => ['nullable', 'boolean'],
            'create_tags' => ['nullable', 'boolean'],
        ]);

        $limit = $validated['limit'] ?? 50;
        $applyBehavior = $validated['apply_behavior'] ?? 'merge';
        $skipExisting = $request->boolean('skip_existing');
        $createTags = $request->boolean('create_tags');

        $query = Link::query()
            ->where('user_id', auth()->id())
            ->with(['tags:id,name'])
            ->orderBy('id');

        if ($request->boolean('untagged_only')) {
            $query->whereDoesntHave('tags');
        }

        if ($request->boolean('exclude_broken')) {
            $query->where('status', '!=', Link::STATUS_BROKEN);
        }

        $links = $query->limit($limit)->get();
        if ($links->isEmpty()) {
            flash('No matching links to suggest tags for.', 'warning');
            return redirect()->route('ai-tagging.index');
        }

        // Send in modest batches to keep each request responsive, then merge the
        // parsed records into a single file the existing preview can consume.
        $parser = app(TagSuggestionParser::class);
        $records = [];
        foreach ($links->chunk(25) as $chunk) {
            $raw = $suggester->suggestForLinks($chunk);
            if ($raw === null) {
                continue;
            }

            foreach ($parser->parse($raw) as $record) {
                if (!empty($record['id']) && !empty($record['tags'])) {
                    $records[] = ['id' => $record['id'], 'tags' => array_values($record['tags'])];
                }
            }
        }

        if (empty($records)) {
            flash('The AI did not return usable suggestions. Check the logs and try again.', 'error');
            return redirect()->route('ai-tagging.index');
        }

        $importDir = storage_path('ai-tagging/imports');
        File::ensureDirectoryExists($importDir);
        $path = $importDir . DIRECTORY_SEPARATOR . 'ai-online-' . now()->format('Ymd-His') . '.json';
        File::put($path, json_encode($records, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) ?: '[]');

        $preview = $this->buildPreview(
            filepath: $path,
            applyBehavior: $applyBehavior,
            skipExisting: $skipExisting,
            createTags: $createTags,
        );

        $request->session()->put(self::PREVIEW_SESSION_KEY, $preview);
        flash('AI suggestions generated. Review the changes below, then Apply.', 'success');

        return redirect()->route('ai-tagging.index');
    }

    public function export(Request $request): BinaryFileResponse|RedirectResponse
    {
        $validated = $request->validate([
            'format' => ['required', 'in:json,csv,markdown'],
            'limit' => ['nullable', 'integer', 'min:1'],
            'untagged_only' => ['nullable', 'boolean'],
            'exclude_broken' => ['nullable', 'boolean'],
        ]);

        $format = $validated['format'];
        $untaggedOnly = $request->boolean('untagged_only');
        $excludeBroken = $request->boolean('exclude_broken');

        $extension = match ($format) {
            'json' => 'json',
            'csv' => 'csv',
            'markdown' => 'md',
        };

        $exportDir = storage_path('ai-tagging/exports');
        File::ensureDirectoryExists($exportDir);

        $filename = 'ai-export-' . now()->format('Ymd-His') . '.' . $extension;
        $path = $exportDir . DIRECTORY_SEPARATOR . $filename;

        $exitCode = Artisan::call('links:export-for-ai', array_filter([
            '--user-email' => auth()->user()->email,
            '--format' => $format,
            '--limit' => $validated['limit'] ?? null,
            '--untagged-only' => $untaggedOnly ? true : null,
            '--exclude-broken' => $excludeBroken ? true : null,
            '--output' => $path,
        ], fn($v) => $v !== null));

        if ($exitCode !== 0 || !File::exists($path)) {
            flash('AI export failed. Please check the logs/output.', 'error');
            return redirect()->route('ai-tagging.index');
        }

        return response()->download($path, $filename)->deleteFileAfterSend(true);
    }

    public function import(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'ai_response' => ['required', 'file', 'max:10240'],
            'apply_behavior' => ['nullable', 'in:merge,replace'],
            'skip_existing' => ['nullable', 'boolean'],
            'create_tags' => ['nullable', 'boolean'],
        ]);

        $applyBehavior = $validated['apply_behavior'] ?? 'merge';
        $skipExisting = $request->boolean('skip_existing');
        $createTags = $request->boolean('create_tags');

        $importDir = storage_path('ai-tagging/imports');
        File::ensureDirectoryExists($importDir);

        $uploaded = $request->file('ai_response');
        $originalName = preg_replace('/[^A-Za-z0-9._-]/', '_', (string) $uploaded->getClientOriginalName()) ?? 'ai-response.txt';
        $filename = 'ai-response-' . now()->format('Ymd-His') . '-' . $originalName;
        $path = $importDir . DIRECTORY_SEPARATOR . $filename;
        $uploaded->move($importDir, $filename);

        $preview = $this->buildPreview(
            filepath: $path,
            applyBehavior: $applyBehavior,
            skipExisting: $skipExisting,
            createTags: $createTags,
        );

        $request->session()->put(self::PREVIEW_SESSION_KEY, $preview);
        flash('Preview generated. Review the changes below, then Apply.', 'success');

        return redirect()->route('ai-tagging.index');
    }

    public function apply(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'confirm_apply' => ['required', 'accepted'],
        ]);

        $preview = $request->session()->get(self::PREVIEW_SESSION_KEY);
        if (!is_array($preview) || empty($preview['filepath'])) {
            flash('No preview found. Run Preview first.', 'warning');
            return redirect()->route('ai-tagging.index');
        }

        $filepath = (string) $preview['filepath'];
        if (!File::exists($filepath)) {
            flash('Preview file is missing. Run Preview again.', 'warning');
            $request->session()->forget(self::PREVIEW_SESSION_KEY);
            return redirect()->route('ai-tagging.index');
        }

        $args = [
            'filepath' => $filepath,
            '--user-email' => auth()->user()->email,
        ];

        if (($preview['apply_behavior'] ?? 'merge') === 'replace') {
            $args['--replace'] = true;
        } else {
            $args['--merge'] = true;
        }

        if (!empty($preview['skip_existing'])) {
            $args['--skip-existing'] = true;
        }

        if (empty($preview['create_tags'])) {
            $args['--no-create-tags'] = true;
        }

        $exitCode = Artisan::call('ai:import-tags', $args);
        $output = trim((string) Artisan::output());

        if ($exitCode !== 0) {
            flash('AI import failed. Review the output below.', 'error');
            return redirect()
                ->route('ai-tagging.index')
                ->with('ai_tagging_output', $output);
        }

        File::delete($filepath);
        $request->session()->forget(self::PREVIEW_SESSION_KEY);

        flash('AI tags imported.', 'success');
        return redirect()
            ->route('ai-tagging.index')
            ->with('ai_tagging_output', $output);
    }

    private function buildPreview(string $filepath, string $applyBehavior, bool $skipExisting, bool $createTags): array
    {
        $raw = (string) File::get($filepath);
        $records = app(TagSuggestionParser::class)->parse($raw);

        $stats = [
            'records' => 0,
            'links_found' => 0,
            'links_not_found' => 0,
            'links_skipped_existing' => 0,
            'links_would_change' => 0,
            'tags_would_create' => 0,
            'unknown_tags_skipped' => 0,
        ];

        $items = [];

        foreach ($records as $record) {
            $stats['records']++;

            $link = $this->resolveLinkForUser($record['id'], $record['url']);
            if (!$link) {
                $stats['links_not_found']++;
                continue;
            }

            $stats['links_found']++;

            $existingTagNames = $link->tags()->pluck('name')->map(fn($n) => (string) $n)->values()->all();
            if ($skipExisting && !empty($existingTagNames)) {
                $stats['links_skipped_existing']++;
                continue;
            }

            $suggestedTagNames = $this->normalizeTagNames($record['tags']);
            if (empty($suggestedTagNames)) {
                continue;
            }

            $resolved = [];
            $wouldCreate = [];
            $wouldSkipUnknown = [];

            foreach ($suggestedTagNames as $tagName) {
                $tag = $this->findTagForUser($tagName);
                if ($tag) {
                    $resolved[] = $tagName;
                    continue;
                }

                if ($createTags) {
                    $wouldCreate[] = $tagName;
                } else {
                    $wouldSkipUnknown[] = $tagName;
                }
            }

            $stats['tags_would_create'] += count($wouldCreate);
            $stats['unknown_tags_skipped'] += count($wouldSkipUnknown);

            $resultTags = $applyBehavior === 'replace'
                ? array_values(array_unique(array_merge($resolved, $wouldCreate)))
                : array_values(array_unique(array_merge($existingTagNames, $resolved, $wouldCreate)));

            $adds = array_values(array_diff($resultTags, $existingTagNames));
            $removes = $applyBehavior === 'replace'
                ? array_values(array_diff($existingTagNames, $resultTags))
                : [];

            if (!empty($adds) || !empty($removes)) {
                $stats['links_would_change']++;
            }

            if (count($items) < 50) {
                $items[] = [
                    'link_id' => $link->id,
                    'title' => $link->title,
                    'url' => $link->url,
                    'existing' => $existingTagNames,
                    'suggested' => $suggestedTagNames,
                    'adds' => $adds,
                    'removes' => $removes,
                    'would_create' => $wouldCreate,
                    'would_skip_unknown' => $wouldSkipUnknown,
                ];
            }
        }

        return [
            'created_at' => now()->toIso8601String(),
            'filepath' => $filepath,
            'apply_behavior' => $applyBehavior,
            'skip_existing' => $skipExisting,
            'create_tags' => $createTags,
            'stats' => $stats,
            'items' => $items,
            'items_limited' => $stats['records'] > count($items),
        ];
    }

    private function resolveLinkForUser(?int $id, ?string $url): ?Link
    {
        if ($id !== null) {
            return Link::query()
                ->where('user_id', auth()->id())
                ->whereKey($id)
                ->first();
        }

        if ($url !== null) {
            return Link::query()
                ->where('user_id', auth()->id())
                ->where('url', $url)
                ->first();
        }

        return null;
    }

    private function findTagForUser(string $name): ?Tag
    {
        $nameLower = mb_strtolower($name);

        return Tag::query()
            ->where('user_id', auth()->id())
            ->whereRaw('LOWER(name) = ?', [$nameLower])
            ->first();
    }

    /**
     * @param array<int,string> $tags
     * @return array<int,string>
     */
    private function normalizeTagNames(array $tags): array
    {
        $out = [];
        $seen = [];

        foreach ($tags as $tag) {
            $tag = trim((string) $tag);
            $tag = preg_replace('/\s+/u', ' ', $tag) ?? $tag;
            $tag = trim($tag, " \t\n\r\0\x0B\"'");
            if ($tag === '') {
                continue;
            }

            $key = mb_strtolower($tag);
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;
            $out[] = $tag;
        }

        return $out;
    }
}
