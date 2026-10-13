<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;
use App\DTO\SourceQuery;

class OpenAlexProvider extends JsonProvider
{
    public function name(): string
    {
        return 'openalex';
    }

    public function findByIdentifier(string $kind, string $value): ProviderResult
    {
        $r = $this->http->get($this->name(), 'works/'.rawurlencode('https://doi.org/'.$value), [], true);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (empty($r['data']['id'])) {
            return $this->malformed($r);
        }

        return $this->result($r, [$this->normalize($r['data'])]);
    }

    public function search(SourceQuery $query): ProviderResult
    {
        $r = $this->http->get($this->name(), 'works', ['search' => $query->title, 'per_page' => 5], false,
            [$query->type, $query->title, $query->authors, $query->year]);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (! is_array($r['data']['results'] ?? null)) {
            return $this->malformed($r);
        }

        return $this->result($r, array_map($this->normalize(...), array_slice($r['data']['results'], 0, 5)));
    }

    private function normalize(array $i): array
    {
        return ['id' => $i['id'], 'doi' => $i['doi'] ?? null, 'title' => $i['title'] ?? null,
            'authors' => array_map(fn ($a) => ['name' => $a['author']['display_name'] ?? ''], $i['authorships'] ?? []),
            'year' => $i['publication_year'] ?? null, 'container_title' => $i['primary_location']['source']['display_name'] ?? null,
            'volume' => $i['biblio']['volume'] ?? null, 'issue' => $i['biblio']['issue'] ?? null,
            'pages' => isset($i['biblio']['first_page']) ? $i['biblio']['first_page'].'-'.($i['biblio']['last_page'] ?? $i['biblio']['first_page']) : null,
            'kind' => $i['type'] ?? null, 'url' => $i['doi'] ?? $i['id']];
    }
}
