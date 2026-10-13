<?php

namespace App\DTO;

readonly class ProviderResult
{
    public function __construct(
        public string $provider,
        public string $status,
        public array $candidates = [],
        public bool $cached = false,
        public ?string $fetchedAt = null,
        public ?int $retryAfter = null,
    ) {}

    public function diagnostic(): array
    {
        return ['provider' => $this->provider, 'status' => $this->status, 'cached' => $this->cached,
            'fetched_at' => $this->fetchedAt, 'retry_after' => $this->retryAfter];
    }
}
