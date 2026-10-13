<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;
use App\DTO\SourceQuery;
use App\Services\Bibliography\SourceNormalizer;

class OpenLibraryProvider extends JsonProvider
{
    public function name(): string
    {
        return 'openlibrary';
    }

    public function findByIdentifier(string $kind, string $value): ProviderResult
    {
        $r = $this->http->get($this->name(), 'isbn/'.rawurlencode($value).'.json', [], true);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (empty($r['data']['key']) || empty($r['data']['title'])) {
            return $this->malformed($r);
        }
        $candidate = $this->edition($r['data']);
        // Fetch author cards only when available; each call uses the same limiter/budget/cache.
        $authors = [];
        $complete = true;
        foreach (array_slice($r['data']['authors'] ?? [], 0, 4) as $author) {
            $key = $author['key'] ?? '';
            if (! preg_match('~^/authors/OL\d+A$~', $key)) {
                continue;
            }
            $card = $this->http->get($this->name(), $key.'.json', [], true);
            if ($card['status'] === 'ok' && isset($card['data']['name'])) {
                $authors[] = ['name' => $card['data']['name']];
            } else {
                $complete = false;
            }
        }
        if (count($r['data']['authors'] ?? []) > 4) {
            $complete = false;
        }
        $candidate['authors'] = $authors;
        $candidate['authors_complete'] = $complete;
        $candidate['metadata_complete'] = $complete;

        return $this->result($r, [$candidate]);
    }

    public function search(SourceQuery $query): ProviderResult
    {
        $params = ['title' => $query->title, 'limit' => 5,
            'fields' => 'key,title,author_name,editions,editions.key,editions.title'];
        if ($query->authors) {
            $params['author'] = implode(' ', $query->authors);
        }
        $r = $this->http->get($this->name(), 'search.json', $params, false, [$query->type, $query->title, $query->authors, $query->year]);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (! is_array($r['data']['docs'] ?? null)) {
            return $this->malformed($r);
        }
        $candidates = [];
        foreach (array_slice($r['data']['docs'], 0, 5) as $work) {
            // Work-level first_publish_year and aggregate ISBNs do not identify an edition.
            $candidate = ['id' => $work['key'] ?? null, 'title' => $work['title'] ?? null,
                'authors' => array_map(fn ($name) => ['name' => $name], $work['author_name'] ?? []),
                'kind' => 'work', 'year' => null,  'url' => 'https://openlibrary.org/'.ltrim($work['key'] ?? '', '/')];
            $editionKey = $work['editions']['docs'][0]['key'] ?? null;
            if ($editionKey && preg_match('~^/books/OL\d+M$~', $editionKey) && count($candidates) < 3) {
                $edition = $this->http->get($this->name(), $editionKey.'.json', [], true);
                if ($edition['status'] === 'ok' && isset($edition['data']['key'], $edition['data']['title'])) {
                    $candidate = $this->edition($edition['data']) + ['authors' => $candidate['authors']];
                } else {
                    $candidate['metadata_complete'] = false;
                }
            }
            $candidates[] = $candidate;
        }

        return $this->result($r, $candidates);
    }

    private function edition(array $i): array
    {
        $normalizer = new SourceNormalizer;

        return ['id' => $i['key'], 'title' => $i['title'], 'kind' => 'edition',
            'isbn' => array_values(array_filter(array_map($normalizer->isbn(...), array_merge($i['isbn_10'] ?? [], $i['isbn_13'] ?? [])))),
            'publisher' => $i['publishers'][0] ?? null, 'year' => $normalizer->year($i['publish_date'] ?? null),
            'url' => 'https://openlibrary.org'.$i['key']];
    }
}
