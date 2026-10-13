<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;
use App\DTO\SourceQuery;

class DblpProvider extends JsonProvider
{
    public function name(): string
    {
        return 'dblp';
    }

    public function findByIdentifier(string $kind, string $value): ProviderResult
    {
        return new ProviderResult($this->name(), 'skipped');
    }

    public function search(SourceQuery $query): ProviderResult
    {
        $r = $this->http->get($this->name(), 'search/publ/api', ['q' => ($query->title).' '.implode(' ', $query->authors).' '.($query->year ?? ''), 'format' => 'json', 'h' => 5]);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        $hits = $r['data']['result']['hits'] ?? null;
        if (! is_array($hits) || ! isset($hits['@total'])) {
            return $this->malformed($r);
        }
        $items = $hits['hit'] ?? [];
        if (isset($items['info'])) {
            $items = [$items];
        }
        if (! is_array($items) || ((int) $hits['@total'] > 0 && ! $items)) {
            return $this->malformed($r);
        }
        $candidates = [];
        foreach (array_slice($items, 0, 5) as $hit) {
            $i = $hit['info'] ?? [];
            $authors = $i['authors']['author'] ?? [];
            if (is_string($authors) || isset($authors['text'])) {
                $authors = [$authors];
            }
            $candidates[] = ['id' => $i['key'] ?? $hit['@id'] ?? null, 'dblp_id' => $i['key'] ?? null,
                'doi' => $i['doi'] ?? null, 'title' => html_entity_decode($i['title'] ?? ''),
                'authors' => array_map(fn ($a) => ['name' => is_array($a) ? ($a['text'] ?? '') : $a], $authors),
                'year' => $i['year'] ?? null, 'container_title' => $i['venue'] ?? null,
                'kind' => $i['type'] ?? null, 'url' => $i['url'] ?? null];
        }

        return $this->result($r, $candidates);
    }
}
