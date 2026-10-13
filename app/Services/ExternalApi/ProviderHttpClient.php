<?php

namespace App\Services\ExternalApi;

use App\Services\Bibliography\DeferredCheck;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class ProviderHttpClient
{
    public int $requests = 0;

    public int $requestBudget;

    private ?\Closure $reserveRequest;

    public function __construct(?int $budget = null, ?callable $reserveRequest = null)
    {
        $this->reserveRequest = $reserveRequest ? \Closure::fromCallable($reserveRequest) : null;
        $this->requestBudget = max(0, min(config('bibliography.max_requests'), $budget ?? config('bibliography.max_requests')));
    }

    public function get(string $provider, string $path, array $params = [], bool $identifier = false, array $identity = []): array
    {
        $key = 'bib:response:'.hash('sha256', json_encode([$provider, $path, $params, $identity, config('bibliography.algorithm_version')]));
        if ($cached = Cache::get($key)) {
            return $cached + ['cached' => true];
        }
        if ($this->requests >= $this->requestBudget) {
            return ['status' => 'skipped'];
        }
        $responseLock = Cache::lock($key.':lock', 20);
        if (! $responseLock->get()) {
            throw new DeferredCheck;
        }
        $slot = Cache::lock('bib:slot:'.$provider, 20);
        $held = false;
        try {
            if ($cached = Cache::get($key)) {
                return $cached + ['cached' => true];
            }
            if (! $slot->get()) {
                throw new DeferredCheck;
            }
            $held = true;
            $cooldown = (float) Cache::get('bib:next:'.$provider, 0) - microtime(true);
            if ($cooldown > 0) {
                throw new DeferredCheck(max(1, (int) ceil($cooldown)));
            }
            if ($provider === 'datacite' && RateLimiter::tooManyAttempts('bib:datacite:window', config('bibliography.providers.datacite.window_limit'))) {
                throw new DeferredCheck(RateLimiter::availableIn('bib:datacite:window'));
            }
            $quotaKey = null;
            if ($provider === 'openalex' && ! $identifier) {
                $quotaKey = 'bib:openalex:searches:'.gmdate('Y-m-d');
                if (Cache::get('bib:quota:openalex') || RateLimiter::tooManyAttempts($quotaKey, config('bibliography.providers.openalex.daily_search_limit'))) {
                    return ['status' => 'quota_exhausted'];
                }
            }
            if ($this->reserveRequest && ! ($this->reserveRequest)()) {
                return ['status' => 'skipped'];
            }
            if ($quotaKey) {
                RateLimiter::hit($quotaKey, max(1, strtotime('tomorrow UTC') - time()));
            }
            $this->requests++;
            if ($provider === 'datacite') {
                RateLimiter::hit('bib:datacite:window', 300);
            }
            Cache::put('bib:next:'.$provider, microtime(true) + config('bibliography.providers.'.$provider.'.interval', 1), 86400);
            $base = $provider === 'openalex' ? config('services.openalex.url') : config('bibliography.providers.'.$provider.'.url');
            $contact = config('bibliography.contact_email');
            $agent = config('bibliography.user_agent').($contact ? ' (mailto:'.$contact.')' : '');
            if ($contact && in_array($provider, ['crossref', 'datacite'])) {
                $params['mailto'] = $contact;
            }
            $client = Http::withOptions(['allow_redirects' => false])->acceptJson()->withUserAgent($agent)->connectTimeout(3)->timeout(config('bibliography.timeout'));
            if ($provider === 'openalex') {
                $client = $client->withToken(config('services.openalex.key'));
            }
            $start = microtime(true);
            try {
                $response = $client->get(rtrim($base, '/').'/'.ltrim($path, '/'), $params);
                $status = match (true) {
                    $response->status() === 404 => 'not_found',
                    $response->status() === 429 => 'rate_limited',
                    in_array($response->status(), [401, 403]) => 'unauthorized',
                    ! $response->successful() => 'unavailable',
                    ! is_array($response->json()) => 'malformed_response',
                    default => 'ok',
                };
                if ($provider === 'openalex') {
                    $remaining = $response->header('X-RateLimit-Remaining');
                    $reset = max(1, (int) ($response->header('X-RateLimit-Reset') ?: strtotime('tomorrow UTC') - time()));
                    if ($remaining !== null && (float) $remaining <= 0) {
                        Cache::put('bib:quota:openalex', true, $reset);
                        if ($status === 'rate_limited') {
                            $status = 'quota_exhausted';
                        }
                    }
                }
                $retry = $response->header('Retry-After');
                $delay = $retry ? (is_numeric($retry) ? (int) $retry : max(1, (int) strtotime($retry) - time())) : 5;
                $result = ['status' => $status, 'data' => $status === 'ok' ? $response->json() : null,
                    'fetched_at' => now()->toIso8601String(), 'retry_after' => max(1, min(86400, $delay))];
            } catch (ConnectionException $e) {
                $result = ['status' => 'unavailable', 'retry_after' => 5];
            }
            Log::info('Bibliography provider request', ['provider' => $provider, 'status' => $result['status'], 'latency_ms' => round((microtime(true) - $start) * 1000)]);
            if (in_array($result['status'], ['ok', 'not_found'])) {
                $ttl = $result['status'] === 'not_found' ? config('bibliography.cache.not_found') : config('bibliography.cache.'.($identifier ? 'identifier' : 'search'));
                $ttl = min($ttl, config('bibliography.providers.'.$provider.'.cache_ttl', $ttl));
                Cache::put($key, $result, $ttl);
            } elseif (in_array($result['status'], ['rate_limited', 'unavailable'])) {
                Cache::put('bib:next:'.$provider, microtime(true) + $result['retry_after'], $result['retry_after'] + 1);
            }

            return $result + ['cached' => false];
        } finally {
            if ($held) {
                $slot->release();
            }
            $responseLock->release();
        }
    }
}
