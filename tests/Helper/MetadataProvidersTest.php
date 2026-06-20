<?php

namespace Tests\Helper;

use App\Services\Metadata\FxTwitterProvider;
use App\Services\Metadata\JinaReaderProvider;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class MetadataProvidersTest extends TestCase
{
    public function test_fxtwitter_handles_only_status_urls(): void
    {
        $provider = new FxTwitterProvider();

        $this->assertTrue($provider->handles('https://x.com/jack/status/20'));
        $this->assertTrue($provider->handles('https://twitter.com/jack/status/20'));
        $this->assertFalse($provider->handles('https://x.com/jack'));
        $this->assertFalse($provider->handles('https://example.com/jack/status/20'));
    }

    public function test_fxtwitter_parses_tweet_meta(): void
    {
        Http::fake([
            'api.fxtwitter.com/*' => Http::response([
                'code' => 200,
                'tweet' => [
                    'text' => 'just setting up my twttr',
                    'author' => ['name' => 'jack', 'screen_name' => 'jack'],
                    'media' => ['photos' => [['url' => 'https://pbs.twimg.com/media/abc.jpg']]],
                ],
            ]),
        ]);

        $meta = (new FxTwitterProvider())->fetch('https://x.com/jack/status/20');

        $this->assertSame('jack (@jack)', $meta['title']);
        $this->assertSame('just setting up my twttr', $meta['description']);
        $this->assertSame('https://pbs.twimg.com/media/abc.jpg', $meta['og:image']);
        $this->assertSame('https://pbs.twimg.com/media/abc.jpg', $meta['twitter:image']);
    }

    public function test_fxtwitter_returns_null_on_failure(): void
    {
        Http::fake(['api.fxtwitter.com/*' => Http::response(status: 404)]);

        $this->assertNull((new FxTwitterProvider())->fetch('https://x.com/jack/status/20'));
    }

    public function test_jina_parses_title_and_description(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response([
                'code' => 200,
                'data' => ['title' => 'Example Page', 'description' => 'A clean description'],
            ]),
        ]);

        $meta = (new JinaReaderProvider())->fetch('https://example.com/hard');

        $this->assertSame('Example Page', $meta['title']);
        $this->assertSame('A clean description', $meta['description']);
    }

    public function test_jina_falls_back_to_content_excerpt(): void
    {
        Http::fake([
            'r.jina.ai/*' => Http::response([
                'code' => 200,
                'data' => ['title' => 'Example', 'content' => str_repeat('word ', 100)],
            ]),
        ]);

        $meta = (new JinaReaderProvider())->fetch('https://example.com/hard');

        $this->assertSame('Example', $meta['title']);
        $this->assertNotEmpty($meta['description']);
        $this->assertStringEndsWith('…', $meta['description']);
    }
}
