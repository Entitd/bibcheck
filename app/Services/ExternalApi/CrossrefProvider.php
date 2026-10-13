<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;
use App\DTO\SourceQuery;

class CrossrefProvider extends JsonProvider
{
    public function name(): string
    {
        return 'crossref';
    }

    public function findByIdentifier(string $kind, string $value): ProviderResult
    {
        $r = $this->http->get($this->name(), 'works/'.rawurlencode($value), [], true);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        $item = $r['data']['message'] ?? null;
        if (! is_array($item) || empty($item['DOI'])) {
            return $this->malformed($r);
        }

        return $this->result($r, [$this->normalize($item)]);
    }

    public function search(SourceQuery $query): ProviderResult
    {
        $params = ['query.bibliographic' => $query->title, 'rows' => 5];
        if ($query->authors) {
            $params['query.author'] = implode(' ', $query->authors);
        }
        $r = $this->http->get($this->name(), 'works', $params, false, [$query->type, $query->title, $query->authors, $query->year]);
        if ($r['status'] !== 'ok') {
            return $this->result($r);
        }
        if (! is_array($r['data']['message']['items'] ?? null)) {
            return $this->malformed($r);
        }

        return $this->result($r, array_map($this->normalize(...), array_slice($r['data']['message']['items'], 0, 5)));
    }

    private function normalize(array $item): array
    {
        $years = [];
        foreach (['published-print', 'published-online', 'published', 'issued'] as $date) {
            if ($year = $item[$date]['date-parts'][0][0] ?? null) {
                $years[] = (int) $year;
            }
        }
        // Crossref update-to describes the records updated BY this record.
        // Only an explicit incoming retraction relation confirms an original was retracted.
        $retraction = ['status' => 'no_notice_found', 'notice_url' => null, 'source' => 'crossref'];
        foreach ($item['relation']['is-retracted-by'] ?? [] as $relation) {
            if (($relation['id-type'] ?? null) === 'doi') {
                $retraction = ['status' => 'retracted', 'notice_url' => 'https://doi.org/'.$relation['id'], 'source' => 'crossref'];
            }
        }
        foreach ($item['updated-by'] ?? [] as $update) {
            if (($update['type'] ?? '') === 'retraction') {
                $retraction = ['status' => 'retracted', 'notice_url' => isset($update['DOI']) ? 'https://doi.org/'.$update['DOI'] : null,
                    'source' => $update['source'] ?? 'crossref', 'notice_date' => $update['updated']['date-time'] ?? null];
            }
        }
        foreach ($item['update-to'] ?? [] as $update) {
            if (($update['type'] ?? '') === 'retraction') {
                $retraction['notice_type'] = 'retraction_notice';
                $retraction['updates_doi'] = $update['DOI'] ?? null;
            }
        }

        return [
            'id' => $item['DOI'] ?? null, 'doi' => $item['DOI'] ?? null,
            'title' => $item['title'][0] ?? null, 'authors' => $item['author'] ?? [],
            'year' => $years[0] ?? null, 'years' => array_values(array_unique($years)),
            'container_title' => $item['container-title'][0] ?? null, 'publisher' => $item['publisher'] ?? null,
            'volume' => $item['volume'] ?? null, 'issue' => $item['issue'] ?? null, 'pages' => $item['page'] ?? null,
            'isbn' => $item['ISBN'] ?? [], 'issn' => $item['ISSN'] ?? [],
            'kind' => $item['type'] ?? null, 'url' => isset($item['DOI']) ? 'https://doi.org/'.$item['DOI'] : null,
            'retraction' => $retraction,
        ];
    }
}
