<?php

namespace App\DTO;

readonly class SourceQuery
{
    public function __construct(
        public string $type,
        public array $fields,
        public ?string $doi,
        public ?string $isbn,
        public string $title,
        public array $authors,
        public ?int $year,
        public array $warnings = [],
        public ?string $error = null,
    ) {}
}
