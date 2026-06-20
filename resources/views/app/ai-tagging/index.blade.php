@extends('layouts.app')

@section('content')

    <div class="card mb-4" id="ai-tag-export">
        <div class="card-header">
            AI Tag Export
        </div>
        <div class="card-body">
            <p class="mb-4">
                Export an AI-friendly list of your links for offline tag suggestions.
            </p>

            <form action="{{ route('ai-tagging.export') }}" method="post">
                @csrf

                <div class="row g-3 align-items-end">
                    <div class="col-12 col-md-3">
                        <label for="format" class="form-label">Format</label>
                        <select name="format" id="format" class="form-select">
                            <option value="json">JSON</option>
                            <option value="csv">CSV</option>
                            <option value="markdown">Markdown</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-3">
                        <label for="limit" class="form-label">Limit</label>
                        <input type="number" min="1" name="limit" id="limit" class="form-control"
                            placeholder="e.g. 50">
                    </div>

                    <div class="col-12 col-md-6">
                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="untagged_only" id="untagged_only"
                                value="1">
                            <label class="form-check-label" for="untagged_only">
                                Export only untagged links
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="exclude_broken" id="exclude_broken"
                                value="1">
                            <label class="form-check-label" for="exclude_broken">
                                Exclude broken links
                            </label>
                        </div>
                    </div>
                </div>

                <button type="submit" class="btn btn-primary mt-4">
                    <x-icon.upload class="me-2"/>
                    Export
                </button>
            </form>

            <hr>

            <h5 class="mb-3">Default AI prompt (copy/paste)</h5>
            <p class="mb-3">
                Copy this prompt into your chat tool, then paste the exported data where it says <code>PASTE EXPORT HERE</code>.
            </p>
            <div class="d-flex flex-wrap gap-2 mb-2">
                <button type="button" class="btn btn-outline-secondary btn-sm" id="copy-ai-prompt">
                    Copy prompt
                </button>
            </div>
            <textarea class="form-control font-monospace" rows="14" id="ai-prompt-text" readonly>{{ $promptText }}</textarea>

            <script>
                (function () {
                    var copyButton = document.getElementById('copy-ai-prompt');
                    var textarea = document.getElementById('ai-prompt-text');
                    if (!copyButton || !textarea) return;

                    copyButton.addEventListener('click', async function () {
                        try {
                            await navigator.clipboard.writeText(textarea.value);
                            copyButton.textContent = 'Copied';
                            setTimeout(function () { copyButton.textContent = 'Copy prompt'; }, 1500);
                        } catch (e) {
                            textarea.focus();
                            textarea.select();
                            document.execCommand('copy');
                        }
                    });
                })();
            </script>
        </div>
    </div>

    <div class="card mb-4" id="ai-tag-auto">
        <div class="card-header">
            Auto-suggest with AI (online)
        </div>
        <div class="card-body">
            @if($onlineEnabled)
                <p class="mb-2">
                    Generate tag suggestions automatically using the configured online model
                    (<code>{{ $onlineModel }}</code>). You will review a preview before anything is applied.
                </p>
                <div class="alert alert-warning">
                    This sends your link data (URL, title, description) to an external AI provider.
                </div>

                <form action="{{ route('ai-tagging.suggest') }}" method="post">
                    @csrf

                    <div class="row g-3 align-items-end">
                        <div class="col-12 col-md-3">
                            <label for="auto_limit" class="form-label">Limit</label>
                            <input type="number" min="1" max="200" name="limit" id="auto_limit" class="form-control"
                                value="50">
                            <div class="form-text">Max 200 links per run.</div>
                        </div>

                        <div class="col-12 col-md-4">
                            <label for="auto_apply_behavior" class="form-label">Apply behavior</label>
                            <select name="apply_behavior" id="auto_apply_behavior" class="form-select">
                                <option value="merge">Merge with existing tags</option>
                                <option value="replace">Replace existing tags</option>
                            </select>
                        </div>

                        <div class="col-12 col-md-5">
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="untagged_only" id="auto_untagged_only"
                                    value="1" checked>
                                <label class="form-check-label" for="auto_untagged_only">
                                    Only untagged links
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="exclude_broken" id="auto_exclude_broken"
                                    value="1" checked>
                                <label class="form-check-label" for="auto_exclude_broken">
                                    Exclude broken links
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="skip_existing" id="auto_skip_existing"
                                    value="1">
                                <label class="form-check-label" for="auto_skip_existing">
                                    Skip links that already have tags
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="checkbox" name="create_tags" id="auto_create_tags"
                                    value="1" checked>
                                <label class="form-check-label" for="auto_create_tags">
                                    Auto-create new tags
                                </label>
                            </div>
                        </div>
                    </div>

                    <button type="submit" class="btn btn-primary mt-4">
                        <x-icon.cog class="me-2"/>
                        Suggest tags with AI
                    </button>
                    <div class="form-text mt-2">
                        Builds a preview below — no changes are applied until you confirm.
                    </div>
                </form>
            @else
                <p class="mb-2">
                    Online AI suggestions are disabled. To enable, set the following in your
                    <code>.env</code> and clear the config cache:
                </p>
                <pre class="mb-0"><code>OPENROUTER_ENABLED=true
OPENROUTER_API_KEY=your-key-from-openrouter.ai
OPENROUTER_MODEL=deepseek/deepseek-chat-v3-0324</code></pre>
            @endif
        </div>
    </div>

    <div class="card mb-4">
        <div class="card-header">
            AI Prompt Template
        </div>
        <div class="card-body">
            <p class="mb-3">
                Generate a prompt template for your offline AI/chat tool.
            </p>

            <div class="alert alert-secondary mb-0">
                Run in terminal:
                <code>php artisan ai:generate-prompt --format=markdown</code>
            </div>
        </div>
    </div>

    <div class="card" id="ai-tag-import">
        <div class="card-header">
            AI Tag Import
        </div>
        <div class="card-body">
            <p class="mb-4">
                Upload the AI output and generate a preview first. When you’re satisfied, apply the changes.
            </p>

            <form action="{{ route('ai-tagging.import') }}" method="post" enctype="multipart/form-data">
                @csrf

                <div class="mb-3">
                    <label for="ai_response" class="form-label">AI response file</label>
                    <input type="file" name="ai_response" id="ai_response" required class="form-control">
                    <div class="form-text">
                        Recommended format: <code>ID URL: tag1, tag2</code> (one per line).
                    </div>
                </div>

                <div class="row g-3">
                    <div class="col-12 col-md-4">
                        <label for="apply_behavior" class="form-label">Apply behavior</label>
                        <select name="apply_behavior" id="apply_behavior" class="form-select">
                            <option value="merge">Merge with existing tags</option>
                            <option value="replace">Replace existing tags</option>
                        </select>
                    </div>

                    <div class="col-12 col-md-8">
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" name="skip_existing" id="skip_existing"
                                value="1">
                            <label class="form-check-label" for="skip_existing">
                                Skip links that already have tags
                            </label>
                        </div>

                        <div class="form-check">
                            <input class="form-check-input" type="checkbox" name="create_tags" id="create_tags"
                                value="1" checked>
                            <label class="form-check-label" for="create_tags">
                                Auto-create new tags
                            </label>
                        </div>
                    </div>
                </div>

                <div class="mt-4">
                    <button type="submit" class="btn btn-outline-primary">
                        <x-icon.cog class="me-2"/>
                        Preview
                    </button>
                    <div class="form-text mt-2">
                        Preview stores the uploaded file temporarily in <code>storage/ai-tagging/imports</code>.
                    </div>
                </div>
            </form>

            @if(session('ai_tagging_preview'))
                @php($preview = session('ai_tagging_preview'))

                <hr>
                <h5 class="mb-3">Preview summary</h5>
                <ul class="mb-3">
                    <li>Apply behavior: <code>{{ $preview['apply_behavior'] ?? 'merge' }}</code></li>
                    <li>Skip existing: <code>{{ !empty($preview['skip_existing']) ? 'yes' : 'no' }}</code></li>
                    <li>Create tags: <code>{{ !empty($preview['create_tags']) ? 'yes' : 'no' }}</code></li>
                    <li>Records parsed: <code>{{ $preview['stats']['records'] ?? 0 }}</code></li>
                    <li>Links found: <code>{{ $preview['stats']['links_found'] ?? 0 }}</code></li>
                    <li>Would change: <code>{{ $preview['stats']['links_would_change'] ?? 0 }}</code></li>
                    <li>Links not found: <code>{{ $preview['stats']['links_not_found'] ?? 0 }}</code></li>
                    @if(!empty($preview['skip_existing']))
                        <li>Skipped (already tagged): <code>{{ $preview['stats']['links_skipped_existing'] ?? 0 }}</code></li>
                    @endif
                    @if(!empty($preview['create_tags']))
                        <li>Tags that would be created: <code>{{ $preview['stats']['tags_would_create'] ?? 0 }}</code></li>
                    @else
                        <li>Unknown tags skipped: <code>{{ $preview['stats']['unknown_tags_skipped'] ?? 0 }}</code></li>
                    @endif
                </ul>

                @if(!empty($preview['items']))
                    <div class="table-responsive">
                        <table class="table table-sm align-middle">
                            <thead>
                                <tr>
                                    <th style="width: 6rem;">Link</th>
                                    <th>Title / URL</th>
                                    <th>Adds</th>
                                    <th>Removes</th>
                                    <th>New tags</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach($preview['items'] as $item)
                                    <tr>
                                        <td>
                                            <code>#{{ $item['link_id'] }}</code>
                                        </td>
                                        <td>
                                            <div class="fw-semibold">{{ $item['title'] }}</div>
                                            <div class="small text-muted">{{ $item['url'] }}</div>
                                        </td>
                                        <td>
                                            @if(!empty($item['adds']))
                                                <span class="text-success">{{ implode(', ', $item['adds']) }}</span>
                                            @else
                                                <span class="text-muted">(none)</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if(!empty($item['removes']))
                                                <span class="text-danger">{{ implode(', ', $item['removes']) }}</span>
                                            @else
                                                <span class="text-muted">(none)</span>
                                            @endif
                                        </td>
                                        <td>
                                            @if(!empty($item['would_create']))
                                                <span class="text-primary">{{ implode(', ', $item['would_create']) }}</span>
                                            @else
                                                <span class="text-muted">(none)</span>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                    @if(!empty($preview['items_limited']))
                        <div class="form-text">Showing the first 50 preview items.</div>
                    @endif
                @endif

                <div class="mt-4">
                    <form action="{{ route('ai-tagging.apply') }}" method="post">
                        @csrf
                        <div class="d-flex align-items-center flex-wrap gap-2">
                            <div class="form-check mb-0">
                                <input class="form-check-input" type="checkbox" name="confirm_apply" id="confirm_apply"
                                    value="1">
                                <label class="form-check-label" for="confirm_apply">
                                    Confirm apply changes from this preview
                                </label>
                            </div>

                            <button type="submit" class="btn btn-danger">
                                <x-icon.check class="me-2"/>
                                Apply
                            </button>
                        </div>
                    </form>
                </div>
            @endif

            @if(session('ai_tagging_output'))
                <hr>
                <h5 class="mb-3">Latest output</h5>
                <pre class="mb-0"><code>{{ session('ai_tagging_output') }}</code></pre>
            @endif
        </div>
    </div>

@endsection
