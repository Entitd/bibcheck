<?php

namespace App\Services\ExternalApi;

use App\DTO\ProviderResult;
use App\DTO\SourceQuery;

interface Provider
{
    public function name(): string;

    public function findByIdentifier(string $kind, string $value): ProviderResult;

    public function search(SourceQuery $query): ProviderResult;
}
