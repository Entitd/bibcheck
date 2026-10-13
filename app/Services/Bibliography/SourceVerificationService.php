<?php

namespace App\Services\Bibliography;

use App\DTO\ProviderResult;
use App\Services\ExternalApi\CrossrefProvider;
use App\Services\ExternalApi\DataCiteProvider;
use App\Services\ExternalApi\DblpProvider;
use App\Services\ExternalApi\OpenAlexProvider;
use App\Services\ExternalApi\OpenLibraryProvider;
use App\Services\ExternalApi\ProviderHttpClient;

class SourceVerificationService
{
    public function __construct(private SourceNormalizer $normalizer, private SourceMatcher $matcher, private ProviderRouter $router) {}

    public function verify(array $fields, string $type = 'article', bool $defer = false, ?int $requestBudget = null, ?callable $reserveRequest = null): array
    {
        $query = $this->normalizer->query($fields, $type);
        $base = ['schema_version' => 2, 'algorithm_version' => config('bibliography.algorithm_version'),
            'found' => false, 'complete' => true, 'matched_by' => null, 'provider' => null,
            'similarity' => null, 'external_title' => null, 'external_data' => null, 'field_checks' => [],
            'candidates' => [], 'provider_checks' => [], 'http_requests' => 0, 'warnings' => $query->warnings,
            'retraction' => ['status' => 'unknown', 'notice_url' => null], 'checked_at' => now()->toIso8601String()];
        if ($query->error) {
            return $this->finish($base, 'invalid_input', $query->error);
        }
        $route = $this->router->route($query);
        if (! config('bibliography.enabled') || ! $route || (! $query->title && ! $query->doi && ! $query->isbn)) {
            return $this->finish($base, 'not_checkable');
        }
        $http = app()->makeWith(ProviderHttpClient::class, ['budget' => $requestBudget, 'reserveRequest' => $reserveRequest]);
        $classes = ['crossref' => CrossrefProvider::class, 'datacite' => DataCiteProvider::class,
            'openlibrary' => OpenLibraryProvider::class, 'dblp' => DblpProvider::class, 'openalex' => OpenAlexProvider::class];
        $candidates = [];
        $identifier = $query->doi ? 'doi' : ($query->isbn ? 'isbn' : null);
        foreach ($route as $name) {
            $provider = app()->makeWith($classes[$name], ['http' => $http]);
            try {
                $result = $identifier ? $provider->findByIdentifier($identifier, $query->$identifier) : $provider->search($query);
            } catch (DeferredCheck $e) {
                $e->requests = $http->requests;
                if ($defer) {
                    throw $e;
                }
                $base['provider_checks'][] = ['provider' => $name, 'status' => 'rate_limited', 'cached' => false, 'retry_after' => $e->delay];
                $base['complete'] = false;

                continue;
            } catch (\TypeError|\UnexpectedValueException|\ErrorException $e) {
                $result = new ProviderResult($name, 'malformed_response');
            }
            $base['http_requests'] = $http->requests;
            $base['provider_checks'][] = $result->diagnostic();
            if ($identifier === 'isbn' && $name === 'openlibrary' && $result->status === 'not_found' && $query->title) {
                try {
                    $result = $provider->search($query);
                    $base['provider_checks'][] = $result->diagnostic();
                } catch (DeferredCheck $e) {
                    $e->requests = $http->requests;
                    if ($defer) {
                        throw $e;
                    }
                    $base['complete'] = false;

                    continue;
                }
            }
            $base['http_requests'] = $http->requests;
            if (! in_array($result->status, ['found', 'not_found'])) {
                $base['complete'] = false;
            }
            foreach ($result->candidates as $candidate) {
                if (($candidate['metadata_complete'] ?? true) === false) {
                    $base['complete'] = false;
                }
                $match = $this->matcher->compare($query, $candidate, $identifier);
                if ($identifier && ($match['field_checks'][$identifier]['status'] ?? null) === 'equal') {
                    $base = array_replace($base, $match, ['provider' => $name, 'matched_by' => $identifier,
                        'external_title' => $candidate['title'] ?? null, 'external_data' => $candidate,
                        'retraction' => ($candidate['retraction'] ?? $base['retraction']) + ['checked_at' => now()->toIso8601String()]]);
                    unset($base['score']);

                    return $this->finish($base, $match['status']);
                }
                if (($match['similarity'] ?? 0) >= 60) {
                    $candidates[] = ['provider' => $name, 'data' => $candidate, 'match' => $match];
                }
            }
            // Universal search precedes DBLP; a strong unambiguous candidate ends this chain.
            if (! $identifier && $result->candidates) {
                $ranked = $candidates;
                usort($ranked, fn ($a, $b) => $b['match']['score'] <=> $a['match']['score']);
                if (($ranked[0]['match']['status'] ?? null) === 'verified' && (count($ranked) === 1 || $ranked[0]['match']['score'] - $ranked[1]['match']['score'] >= config('bibliography.candidate_gap'))) {
                    break;
                }
            }
        }
        if (! $candidates) {
            return $this->finish($base, $base['complete'] ? 'not_found' : 'incomplete');
        }
        usort($candidates, fn ($a, $b) => $b['match']['score'] <=> $a['match']['score']);
        $best = $candidates[0];
        $status = $best['match']['status'];
        if (count($candidates) > 1 && $best['match']['score'] - $candidates[1]['match']['score'] < config('bibliography.candidate_gap')) {
            $status = 'ambiguous';
        }
        $base = array_replace($base, $best['match'], ['provider' => $best['provider'], 'matched_by' => 'metadata',
            'external_title' => $best['data']['title'] ?? null, 'external_data' => $best['data'],
            'candidates' => array_map(fn ($c) => ['provider' => $c['provider'], 'data' => $c['data'], 'similarity' => $c['match']['similarity']], array_slice($candidates, 0, 5)),
            'retraction' => ($best['data']['retraction'] ?? $base['retraction']) + ['checked_at' => now()->toIso8601String()]]);
        if ($status === 'ambiguous') {
            $base['found'] = false;
        }
        if (! $base['complete'] && $status === 'verified') {
            $status = 'partial_match';
        }
        unset($base['score']);

        return $this->finish($base, $status);
    }

    private function finish(array $result, string $status, ?string $message = null): array
    {
        $messages = ['verified' => 'Публикация найдена, проверяемые поля согласуются.',
            'mismatch' => 'Публикация найдена, но есть расхождения в полях.',
            'partial_match' => 'Найдено частичное подтверждение; часть данных не подтверждена.',
            'ambiguous' => 'Найдено несколько близких кандидатов.',
            'not_found' => 'Поиск в выбранных базах не дал результата. Это не доказывает, что источник вымышлен.',
            'not_checkable' => 'Нет подходящей проверки для этого типа или набора данных.',
            'incomplete' => 'Проверка не завершена из-за ограничения или сбоя внешней базы.',
            'invalid_input' => 'Некорректные исходные данные.'];

        return array_replace($result, ['status' => $status, 'message' => $message ?? $messages[$status]]);
    }
}
