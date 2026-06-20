<?php

namespace Tests\Models;

use App\Services\AITagging\PromptTemplate;
use Tests\TestCase;

class PromptTemplateTest extends TestCase
{
    private PromptTemplate $template;

    protected function setUp(): void
    {
        parent::setUp();
        $this->template = new PromptTemplate();
    }

    public function test_api_prompt_has_no_vocabulary_block_by_default(): void
    {
        $prompt = $this->template->systemPromptForApi();

        $this->assertStringNotContainsString('Existing tag vocabulary', $prompt);
        $this->assertStringContainsString('Return ONLY a JSON array', $prompt);
    }

    public function test_api_prompt_includes_preferred_vocabulary(): void
    {
        $prompt = $this->template->systemPromptForApi(['php', 'laravel'], false);

        $this->assertStringContainsString('strongly prefer reusing', $prompt);
        $this->assertStringContainsString('php, laravel', $prompt);
    }

    public function test_api_prompt_existing_only_forbids_new_tags(): void
    {
        $prompt = $this->template->systemPromptForApi(['php'], true);

        $this->assertStringContainsString('choose ONLY from this list', $prompt);
        $this->assertStringContainsString('do NOT invent new tags', $prompt);
    }

    public function test_api_prompt_renders_max_tags_rule(): void
    {
        $prompt = $this->template->systemPromptForApi(['php'], true, 3);

        $this->assertStringContainsString('at most 3', $prompt);
        $this->assertStringContainsString('NO MORE THAN 3', $prompt);
    }
}
