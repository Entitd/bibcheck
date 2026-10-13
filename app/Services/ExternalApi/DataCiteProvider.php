<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;
use App\DTO\SourceQuery;

class DataCiteProvider extends JsonProvider
{
    public function name(): string
    {
        return 'datacite';
    }

    public function findByIdentifier(string $kind, string $value): ProviderResult
    {
        $r = $this->http->get($this->name(), 'dois/'.rawurlencode($value), [], true);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (! is_array($r['data']['data']['attributes'] ?? null)) {
            return $this->malformed($r);
        }

        return $this->result($r, [$this->normalize($r['data']['data'])]);
    }

    public function search(SourceQuery $query): ProviderResult
    {
        $title = addcslashes($query->title, '\\"');
        $params = ['query' => 'titles.title:"'.$title.'"', 'page[size]' => 5];
        // Include all inputs in cache identity without changing DataCite's query syntax.
        $params['query'] .= $query->authors ? ' AND creators.name:"'.addcslashes($query->authors[0], '\\"').'"' : '';
        if ($query->year) {
            $params['query'] .= ' AND publicationYear:'.$query->year;
        }
        $r = $this->http->get($this->name(), 'dois', $params, false, [$query->type, $query->title, $query->authors, $query->year]);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (! is_array($r['data']['data'] ?? null) || ! array_is_list($r['data']['data'])) {
            return $this->malformed($r);
        }

        return $this->result($r, array_map($this->normalize(...), array_slice($r['data']['data'], 0, 5)));
    }

    private function normalize(array $item): array
    {
        $a = $item['attributes'] ?? [];

        return ['id' => $item['id'] ?? null, 'doi' => $a['doi'] ?? $item['id'] ?? null,
            'title' => $a['titles'][0]['title'] ?? null,
            'authors' => array_map(fn ($a) => ['family' => $a['familyName'] ?? null, 'given' => $a['givenName'] ?? null, 'name' => $a['name'] ?? null], $a['creators'] ?? []),
            'year' => $a['publicationYear'] ?? null, 'publisher' => is_array($a['publisher'] ?? null) ? ($a['publisher']['name'] ?? null) : ($a['publisher'] ?? null),
            'kind' => $a['types']['resourceTypeGeneral'] ?? null,
            'identifiers' => $a['identifiers'] ?? [], 'related_identifiers' => $a['relatedIdentifiers'] ?? [],
            'url' => isset($item['id']) ? 'https://doi.org/'.$item['id'] : null];
    }
}
