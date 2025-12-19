<?php

namespace App\Console\Commands;

use App\Services\AITagging\PromptTemplate;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

class GenerateAIPrompt extends Command
{
    protected $signature = 'ai:generate-prompt
                        {--format=markdown : Output format (markdown, text).}
                        {--output= : Write output to a file (relative paths are stored in storage/).}';

    protected $description = 'Generate a prompt template for offline AI tag suggestions.';

    public function handle(): int
    {
        $format = strtolower((string) $this->option('format'));
        if (!in_array($format, ['markdown', 'text'], true)) {
            $this->error('Invalid --format. Allowed: markdown, text.');
            return self::INVALID;
        }

        $template = app(PromptTemplate::class);
        $prompt = $format === 'text'
            ? $template->renderText()
            : $template->renderMarkdown();

        $output = $this->option('output');
        if ($output !== null) {
            $path = $this->resolvePath($output);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $prompt);
            $this->info('Wrote prompt to "' . $path . '"');
            return self::SUCCESS;
        }

        $this->line($prompt);
        return self::SUCCESS;
    }

    private function resolvePath(string $path): string
    {
        return str_starts_with($path, '/') ? $path : storage_path($path);
    }

}
