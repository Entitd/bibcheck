<?php

namespace App\Services\Bibliography;

use App\DTO\SourceQuery;

class ProviderRouter
{
    public function route(SourceQuery $query): array
    {
        $route = match (true) {
            $query->doi !== null => ['crossref', 'datacite', 'openalex'],
            $query->isbn !== null || in_array($query->type, ['book', 'mvbook', 'booklet']) => ['openlibrary'],
            in_array($query->type, ['dataset', 'software', 'thesis', 'phdthesis', 'mastersthesis', 'report', 'techreport']) => ['datacite', 'openalex'],
            in_array($query->type, ['article', 'inproceedings', 'proceedings']) => ['crossref', 'openalex', 'dblp'],
            in_array($query->type, ['incollection', 'inbook']) => ['crossref'],
            default => [],
        };

        return array_values(array_filter($route, fn ($name) => config('bibliography.providers.'.$name.'.enabled') && ($name !== 'openalex' || filled(config('services.openalex.key')))));
    }
}
