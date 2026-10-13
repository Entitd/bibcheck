<?php

namespace App\Services\Bibliography;

use App\DTO\SourceQuery;

class SourceMatcher
{
    public function __construct(private SourceNormalizer $normalizer) {}

    public function similarity(string $a, string $b): float
    {
        $a = $this->normalizer->text($a);
        $b = $this->normalizer->text($b);
        similar_text($a, $b, $percent);
        $wordsA = array_unique(preg_split('/[^\p{L}\p{N}]+/u', $a, -1, PREG_SPLIT_NO_EMPTY));
        $wordsB = array_unique(preg_split('/[^\p{L}\p{N}]+/u', $b, -1, PREG_SPLIT_NO_EMPTY));
        $union = array_unique(array_merge($wordsA, $wordsB));
        $overlap = $union ? count(array_intersect($wordsA, $wordsB)) / count($union) * 100 : $percent;

        return round(($percent + $overlap) / 2, 2);
    }

    public function compare(SourceQuery $query, array $candidate, ?string $identifier = null): array
    {
        $checks = [];
        $similarity = $query->title && ! empty($candidate['title']) ? $this->similarity($query->title, $candidate['title']) : null;
        if ($query->title) {
            $checks['title'] = $this->check($query->fields['title'], $candidate['title'] ?? null, $similarity >= config('bibliography.title_threshold'));
        }
        if ($query->authors) {
            $inputAuthors = $this->normalizer->authorDetails($query->fields['author']);
            $externalAuthors = [];
            foreach ($candidate['authors'] ?? [] as $author) {
                if (is_string($author)) {
                    $author = ['name' => $author];
                }
                $details = ! empty($author['family'])
                    ? [['family' => $this->normalizer->text($author['family']), 'given' => $this->normalizer->text($author['given'] ?? '')]]
                    : $this->normalizer->authorDetails($author['name'] ?? '');
                $externalAuthors = array_merge($externalAuthors, $details);
            }
            $authorStatus = $externalAuthors ? 'equal' : 'unavailable';
            foreach ($inputAuthors as $input) {
                $familyMatches = array_values(array_filter($externalAuthors, fn ($a) => $a['family'] === $input['family']));
                if (! $familyMatches) {
                    $authorStatus = $externalAuthors ? 'different' : 'unavailable';
                    break;
                }
                if (! $input['given']) {
                    continue;
                }
                $givenMatch = false;
                $missing = false;
                foreach ($familyMatches as $external) {
                    if (! $external['given']) {
                        $missing = true;
                    } elseif ($this->givenNamesAgree($input['given'], $external['given'])) {
                        $givenMatch = true;
                    }
                }
                if (! $givenMatch) {
                    $authorStatus = $missing ? 'unavailable' : 'different';
                    break;
                }
            }
            if ($authorStatus === 'equal' && ! preg_match('/\band\s+others\s*$/i', $query->fields['author']) && count($inputAuthors) !== count($externalAuthors)) {
                $authorStatus = 'different';
            }
            if (($candidate['authors_complete'] ?? true) === false) {
                $authorStatus = 'unavailable';
            }
            $checks['author'] = ['status' => $authorStatus, 'input' => $inputAuthors, 'external' => $externalAuthors];
        }
        if ($query->year) {
            $years = array_map('intval', $candidate['years'] ?? array_filter([$candidate['year'] ?? null]));
            $checks['year'] = $this->check($query->year, $years ?: null, in_array($query->year, $years, true));
        }
        foreach (['doi', 'isbn'] as $kind) {
            if (! $query->$kind) {
                continue;
            }
            $external = $candidate[$kind] ?? null;
            $values = $kind === 'isbn' ? array_values(array_filter(array_map($this->normalizer->isbn(...), (array) $external))) : ($external ? [$this->normalizer->doi($external)] : []);
            $checks[$kind] = $this->check($query->$kind, $values ?: null, in_array($query->$kind, $values, true));
        }
        foreach (['journal' => 'container_title', 'journaltitle' => 'container_title', 'booktitle' => 'container_title', 'publisher' => 'publisher', 'volume' => 'volume', 'number' => 'issue', 'pages' => 'pages'] as $field => $externalField) {
            if (! filled($query->fields[$field] ?? null)) {
                continue;
            }
            $external = $candidate[$externalField] ?? null;
            $a = $this->normalizer->text($query->fields[$field]);
            $b = $this->normalizer->text((string) $external);
            if ($field === 'pages') {
                $a = preg_replace('/[-–—]+/u', '-', $a);
                $b = preg_replace('/[-–—]+/u', '-', $b);
            }
            $checks[$field] = $this->check($query->fields[$field], $external, $a === $b);
        }
        $statuses = array_column($checks, 'status');
        $exact = $identifier !== null && ($checks[$identifier]['status'] ?? null) === 'equal';
        $strong = ($checks['title']['status'] ?? null) === 'equal';
        $corroborated = ($checks['author']['status'] ?? null) === 'equal' && ($checks['year']['status'] ?? null) === 'equal';
        $different = in_array('different', $statuses, true);
        $missing = in_array('unavailable', $statuses, true);
        $identity = $exact || ($strong && $corroborated);
        $status = match (true) {
            $identity && $different => 'mismatch',
            $identity && ! $missing && ($candidate['kind'] ?? null) !== 'work' && ! $query->warnings => 'verified',
            default => 'partial_match',
        };
        $score = ($similarity ?? 0) + (($checks['author']['status'] ?? null) === 'equal' ? 15 : 0) + (($checks['year']['status'] ?? null) === 'equal' ? 10 : 0);

        return ['status' => $status, 'found' => $identity, 'similarity' => $similarity, 'field_checks' => $checks, 'score' => $score];
    }

    private function givenNamesAgree(string $a, string $b): bool
    {
        $a = preg_split('/[.\s-]+/u', $a, -1, PREG_SPLIT_NO_EMPTY);
        $b = preg_split('/[.\s-]+/u', $b, -1, PREG_SPLIT_NO_EMPTY);
        foreach ($a as $i => $part) {
            if (! isset($b[$i])) {
                return false;
            }
            if (mb_strlen($part) === 1 || mb_strlen($b[$i]) === 1) {
                if (mb_substr($part, 0, 1) !== mb_substr($b[$i], 0, 1)) {
                    return false;
                }
            } elseif ($part !== $b[$i]) {
                return false;
            }
        }

        return true;
    }

    private function check(mixed $input, mixed $external, bool $equal): array
    {
        return ['status' => $external === null || $external === '' ? 'unavailable' : ($equal ? 'equal' : 'different'), 'input' => $input, 'external' => $external];
    }
}
