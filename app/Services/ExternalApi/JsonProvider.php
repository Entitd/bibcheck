<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;

abstract class JsonProvider implements Provider
{
    public function __construct(protected ProviderHttpClient $http) {}

    protected function result(array $response, array $candidates = []): ProviderResult
    {
        foreach ($candidates as $candidate) {
            if (! is_array($candidate)) {
                return new ProviderResult($this->name(), 'malformed_response');
            }
            foreach (['title', 'doi', 'container_title', 'publisher', 'volume', 'issue', 'pages', 'url'] as $field) {
                if (isset($candidate[$field]) && ! is_scalar($candidate[$field])) {
                    return new ProviderResult($this->name(), 'malformed_response');
                }
            }
            if (isset($candidate['authors']) && ! is_array($candidate['authors'])) {
                return new ProviderResult($this->name(), 'malformed_response');
            }
            foreach ($candidate['authors'] ?? [] as $author) {
                if (! is_string($author) && ! is_array($author)) {
                    return new ProviderResult($this->name(), 'malformed_response');
                }
                if (is_array($author)) {
                    foreach (['family', 'given', 'name'] as $field) {
                        if (isset($author[$field]) && ! is_string($author[$field])) {
                            return new ProviderResult($this->name(), 'malformed_response');
                        }
                    }
                }
            }
        }
        $status = $response['status'];
        if ($status === 'ok') {
            $status = $candidates ? 'found' : 'not_found';
        }

        return new ProviderResult($this->name(), $status, $candidates, $response['cached'] ?? false,
            $response['fetched_at'] ?? null, $response['retry_after'] ?? null);
    }

    protected function malformed(array $response): ProviderResult
    {
        $response['status'] = 'malformed_response';

        return $this->result($response);
    }
}
