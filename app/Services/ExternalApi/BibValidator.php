<?php

namespace App\Services\ExternalApi;

use App\Services\Bibliography\SourceMatcher;
use App\Services\Bibliography\SourceVerificationService;

class BibValidator
{
    public function __construct(private SourceVerificationService $verification, private SourceMatcher $matcher) {}

    public function calculateSimilarity(string $str1, string $str2): float
    {
        return $this->matcher->similarity($str1, $str2) / 100;
    }

    public function verifySourceOnline(array $fields, string $type = 'article'): array
    {
        return $this->verification->verify($fields, $type);
    }
}
