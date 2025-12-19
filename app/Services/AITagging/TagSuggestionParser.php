<?php

namespace App\Services\AITagging;

use Illuminate\Support\Collection;

class TagSuggestionParser
{
    /**
     * @return Collection<int, array{raw:string,id:int|null,url:string|null,tags:array<int,string>}>
     */
    public function parse(string $raw): Collection
    {
        $trimmed = ltrim($raw);
        if ($trimmed !== '' && ($trimmed[0] === '[' || $trimmed[0] === '{')) {
            $json = json_decode($raw, true);
            if (is_array($json)) {
                return $this->parseJsonRecords($json);
            }
        }

        return $this->parseTextRecords($raw);
    }

    /**
     * @param mixed $json
     * @return Collection<int, array{raw:string,id:int|null,url:string|null,tags:array<int,string>}>
     */
    private function parseJsonRecords($json): Collection
    {
        $records = collect();

        $rows = $json;
        if (isset($json['links']) && is_array($json['links'])) {
            $rows = $json['links'];
        }

        if (!is_array($rows)) {
            return $records;
        }

        foreach ($rows as $row) {
            if (!is_array($row)) {
                continue;
            }

            $id = isset($row['id']) ? (int) $row['id'] : null;
            $url = isset($row['url']) ? (string) $row['url'] : null;
            $tags = $row['tags'] ?? ($row['suggested_tags'] ?? null);

            $tagNames = [];
            if (is_array($tags)) {
                $tagNames = array_values(array_map('strval', $tags));
            } elseif (is_string($tags)) {
                $tagNames = array_values(array_filter(array_map('trim', explode(',', $tags))));
            }

            $records->push([
                'raw' => json_encode($row) ?: '[unparseable json row]',
                'id' => $id ?: null,
                'url' => $url ?: null,
                'tags' => $tagNames,
            ]);
        }

        return $records;
    }

    /**
     * @return Collection<int, array{raw:string,id:int|null,url:string|null,tags:array<int,string>}>
     */
    private function parseTextRecords(string $raw): Collection
    {
        $records = collect();

        foreach (preg_split('/\R/u', $raw) ?: [] as $line) {
            $original = $line;
            $line = trim($line);

            if ($line === '' || str_starts_with($line, '#') || str_starts_with($line, '//')) {
                continue;
            }

            // Strip common markdown/bullet prefixes.
            $line = preg_replace('/^[-*•]\s+/u', '', $line) ?? $line;

            // Normalize markdown links like [text](https://example.com) to the URL.
            $line = preg_replace_callback(
                '/\[(?<text>[^\]]+)\]\((?<url>https?:\/\/[^\s)]+)\)/u',
                fn($m) => $m['url'],
                $line
            ) ?? $line;

            if (preg_match('/^(\d+)\s+(\S+)\s*:\s*(.+)$/u', $line, $m) === 1) {
                $records->push([
                    'raw' => $original,
                    'id' => (int) $m[1],
                    'url' => $m[2],
                    'tags' => $this->splitTags($m[3]),
                ]);
                continue;
            }

            if (preg_match('/^(\d+)[.)]\s+(\S+)\s*:\s*(.+)$/u', $line, $m) === 1) {
                $records->push([
                    'raw' => $original,
                    'id' => (int) $m[1],
                    'url' => $m[2],
                    'tags' => $this->splitTags($m[3]),
                ]);
                continue;
            }

            if (preg_match('/^(\d+)\s*:\s*(.+)$/u', $line, $m) === 1) {
                $records->push([
                    'raw' => $original,
                    'id' => (int) $m[1],
                    'url' => null,
                    'tags' => $this->splitTags($m[2]),
                ]);
                continue;
            }

            if (preg_match('/^(\d+)[.)]\s*:\s*(.+)$/u', $line, $m) === 1) {
                $records->push([
                    'raw' => $original,
                    'id' => (int) $m[1],
                    'url' => null,
                    'tags' => $this->splitTags($m[2]),
                ]);
                continue;
            }

            if (preg_match('/^(https?:\/\/\S+)\s*:\s*(.+)$/u', $line, $m) === 1) {
                $records->push([
                    'raw' => $original,
                    'id' => null,
                    'url' => $m[1],
                    'tags' => $this->splitTags($m[2]),
                ]);
                continue;
            }

            if (preg_match('/^<\s*(https?:\/\/\S+)\s*>\s*:\s*(.+)$/u', $line, $m) === 1) {
                $records->push([
                    'raw' => $original,
                    'id' => null,
                    'url' => $m[1],
                    'tags' => $this->splitTags($m[2]),
                ]);
                continue;
            }

            // Silently ignore unrecognized lines here; callers can validate based on empty result.
        }

        return $records;
    }

    /**
     * @return array<int,string>
     */
    private function splitTags(string $csv): array
    {
        $csv = trim($csv);
        if ($csv === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $csv))));
    }
}
