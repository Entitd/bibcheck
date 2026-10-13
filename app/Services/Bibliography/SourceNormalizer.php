<?php

namespace App\Services\Bibliography;

use App\DTO\SourceQuery;

class SourceNormalizer
{
    public function query(array $fields, string $type = 'article'): SourceQuery
    {
        $doi = filled($fields['doi'] ?? null) ? $this->doi($fields['doi']) : null;
        $isbn = filled($fields['isbn'] ?? null) ? $this->isbn($fields['isbn']) : null;
        $year = $this->year($fields['year'] ?? $fields['date'] ?? null);
        $warnings = [];
        if (isset($fields['year'], $fields['date']) && $this->year($fields['date']) !== $year) {
            $warnings[] = 'Поля year и date противоречат друг другу.';
        }
        $error = null;
        if ($doi !== null && ! preg_match('~^10\.\d{4,9}/\S+$~u', $doi)) {
            $error = 'Некорректный формат DOI.';
        }
        if (filled($fields['isbn'] ?? null) && $isbn === null) {
            $error = 'Некорректная контрольная сумма ISBN.';
        }

        return new SourceQuery(strtolower($type), $fields, $doi, $isbn,
            $this->text($fields['title'] ?? ''), $this->authors($fields['author'] ?? ''), $year, $warnings, $error);
    }

    public function doi(string $value): string
    {
        return mb_strtolower(trim(preg_replace('~^(?:doi:\s*|https?://(?:dx\.)?doi\.org/)~i', '', trim($value))));
    }

    public function isbn(string $value): ?string
    {
        $value = strtoupper(preg_replace('/[\s-]/u', '', trim($value)));
        if (preg_match('/^\d{9}[\dX]$/', $value)) {
            $sum = 0;
            for ($i = 0; $i < 10; $i++) {
                $sum += (10 - $i) * ($value[$i] === 'X' ? 10 : (int) $value[$i]);
            }
            if ($sum % 11 !== 0) {
                return null;
            }
            $value = '978'.substr($value, 0, 9);
            $sum = 0;
            for ($i = 0; $i < 12; $i++) {
                $sum += (int) $value[$i] * ($i % 2 ? 3 : 1);
            }

            return $value.((10 - $sum % 10) % 10);
        }
        if (! preg_match('/^97[89]\d{10}$/', $value)) {
            return null;
        }
        $sum = 0;
        for ($i = 0; $i < 13; $i++) {
            $sum += (int) $value[$i] * ($i % 2 ? 3 : 1);
        }

        return $sum % 10 === 0 ? $value : null;
    }

    public function text(string $value): string
    {
        $value = html_entity_decode(strip_tags($value), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $value = preg_replace('/\\\\(?:textit|textbf|emph|textrm)\s*(?=\{)/u', '', $value);
        $accents = ["'" => "\u{0301}", '"' => "\u{0308}", '`' => "\u{0300}", '^' => "\u{0302}", '~' => "\u{0303}", '=' => "\u{0304}"];
        $value = preg_replace_callback('/\\\\([\'"`^~=])\s*\{?([a-zA-Z])\}?/u', fn ($match) => \Normalizer::normalize($match[2].$accents[$match[1]], \Normalizer::FORM_C), $value);
        $value = str_replace(['\\&', '\\%', '\\_', '\\$'], ['&', '%', '_', '$'], $value);
        $value = str_replace(['{', '}'], '', $value);

        $value = \Normalizer::normalize($value, \Normalizer::FORM_C);

        return trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)));
    }

    public function year(string|int|null $value): ?int
    {
        return preg_match('/\b(\d{4})\b/', (string) $value, $match) ? (int) $match[1] : null;
    }

    public function authors(string $value): array
    {
        return array_column($this->authorDetails($value), 'family');
    }

    public function authorDetails(string $value): array
    {
        $details = [];
        foreach (preg_split('/\s+and\s+/iu', trim($value)) ?: [] as $name) {
            $name = trim($name);
            if (! $name || strtolower($name) === 'others') {
                continue;
            }
            $given = '';
            if (str_starts_with($name, '{')) {
                $family = $name;
            } elseif (str_contains($name, ',')) {
                $parts = explode(',', $name);
                $family = array_shift($parts);
                $given = array_pop($parts) ?? '';
            } else {
                $parts = preg_split('/\s+/u', $name);
                $family = array_pop($parts);
                while ($parts && in_array(mb_strtolower(end($parts)), ['van', 'von', 'de', 'del', 'der', 'den', 'la', 'le', 'da', 'dos'])) {
                    $family = array_pop($parts).' '.$family;
                }
                $given = implode(' ', $parts);
            }
            $details[] = ['family' => $this->text($family), 'given' => $this->text($given)];
        }

        return $details;
    }
}
