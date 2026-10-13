<?php

namespace App\Services;

use App\DTO\BibEntryDTO;
use App\Models\CourseRequirement;
use App\Services\Bibtex\GostValidator;
use App\Services\Bibtex\Parser;
use App\Services\ExternalApi\BibValidator;
use Illuminate\Validation\ValidationException;

class BibtexService
{
    public function __construct(
        private Parser $parser,
        private GostValidator $gost,
        private BibValidator $api
    ) {}

    public function localCheck(string $bibText): array
    {
        $data = $this->parser->parse($bibText);

        $this->assertUniqueKeys($data['entries']);
        $results = [];
        $errors = $data['error'] ?? [];

        /** @var BibEntryDTO $entry */
        foreach ($data['entries'] as $entry) {
            $gostErrors = $this->gost->validate($entry);
            $errors = array_merge($errors, $gostErrors);

            $results[$entry->key] = [
                'type' => $entry->type,
                'line' => $entry->startLine,
                'fields' => $entry->fields,
                'gost_errors' => $gostErrors,
            ];
        }

        return [
            'errors' => $errors,
            'entries' => $results,
            'aggregated_metrics' => $this->gost->calculateMetrics($data['entries']),
        ];
    }

    public function fullCheck(string $bibText): array
    {
        if (count($this->parser->parse($bibText)['entries']) > config('bibliography.sync_max_entries')) {
            throw ValidationException::withMessages(['text' => 'Синхронная проверка ограничена '.config('bibliography.sync_max_entries').' записями. Используйте POST /bibliography/external-checks с сессией пользователя.']);
        }
        $analysis = $this->localCheck($bibText);
        $department = $this->compareWithDepartmentRequirements($analysis['aggregated_metrics']);

        foreach ($analysis['entries'] as $key => $entry) {
            $apiResult = $this->api->verifySourceOnline($entry['fields'], $entry['type']);

            $analysis['entries'][$key]['api_check'] = $apiResult;
        }

        $apiMetrics = $this->calculateApiMetrics($analysis['entries']);

        $analysis['aggregated_metrics'] = array_merge($analysis['aggregated_metrics'], $apiMetrics);
        $analysis['department'] = $department;
        $analysis['course_comparison_result'] = $department['verdict'];

        return $analysis;
    }

    public function departmentCheck(string $bibText): array
    {
        $data = $this->parser->parse($bibText);
        $metrics = $this->gost->calculateMetrics($data['entries']);

        return [
            'errors' => $data['error'] ?? [],
            'entries' => $this->mapEntries($data['entries']),
            'aggregated_metrics' => $metrics,
            'department' => $this->compareWithDepartmentRequirements($metrics),
        ];
    }

    public function externalCheck(string $bibText): array
    {
        if (count($this->parser->parse($bibText)['entries']) > config('bibliography.sync_max_entries')) {
            throw ValidationException::withMessages(['text' => 'Синхронная проверка ограничена '.config('bibliography.sync_max_entries').' записями. Используйте POST /bibliography/external-checks с сессией пользователя.']);
        }
        $data = $this->parser->parse($bibText);
        $entries = $this->mapEntries($data['entries']);

        foreach ($entries as $key => $entry) {
            $entries[$key]['api_check'] = $this->api->verifySourceOnline($entry['fields'], $entry['type']);
        }

        return [
            'errors' => $data['error'] ?? [],
            'entries' => $entries,
            'aggregated_metrics' => $this->calculateApiMetrics($entries),
        ];
    }

    public function verifySource(array $fields, string $type = 'article'): array
    {
        return $this->api->verifySourceOnline($fields, $type);
    }

    private function assertUniqueKeys(array $entries): void
    {
        $keys = array_map(fn ($entry) => $entry->key, $entries);
        if (count($keys) !== count(array_unique($keys))) {
            throw ValidationException::withMessages(['text' => 'Обнаружены повторяющиеся ключи BibLaTeX.']);
        }
    }

    private function mapEntries(array $entries): array
    {
        $this->assertUniqueKeys($entries);
        $mapped = [];

        /** @var BibEntryDTO $entry */
        foreach ($entries as $entry) {
            $mapped[$entry->key] = [
                'type' => $entry->type,
                'line' => $entry->startLine,
                'fields' => $entry->fields,
            ];
        }

        return $mapped;
    }

    private function compareWithDepartmentRequirements(array $metrics): array
    {
        $requirements = CourseRequirement::query()
            ->orderBy('course_number')
            ->get();

        $results = [];
        $bestMatch = null;
        $passedCourse = null;

        foreach ($requirements as $requirement) {
            $checks = [
                'totalQuantity' => [
                    'actual' => $metrics['totalQuantity'] ?? 0,
                    'required' => $requirement->min_total_quantity,
                ],
                'amountOfLiteratureInForeignLanguages' => [
                    'actual' => $metrics['amountOfLiteratureInForeignLanguages'] ?? 0,
                    'required' => $requirement->min_foreign_lang,
                ],
                'numberOfCurrentScientificPeriodicals' => [
                    'actual' => $metrics['numberOfCurrentScientificPeriodicals'] ?? 0,
                    'required' => $requirement->min_current_periodicals,
                ],
                'Literature21Century' => [
                    'actual' => $metrics['Literature21Century'] ?? 0,
                    'required' => $requirement->min_21st_century,
                ],
            ];

            foreach ($checks as $name => $check) {
                $checks[$name]['passed'] = $check['actual'] >= $check['required'];
            }

            $passedChecks = collect($checks)->where('passed', true)->count();
            $passed = $passedChecks === count($checks);

            $courseResult = [
                'course_number' => $requirement->course_number,
                'passed' => $passed,
                'passed_checks' => $passedChecks,
                'total_checks' => count($checks),
                'checks' => $checks,
            ];

            $results[] = $courseResult;

            if ($passed) {
                $passedCourse = $requirement->course_number;
            }

            if ($bestMatch === null || $passedChecks > $bestMatch['passed_checks']) {
                $bestMatch = $courseResult;
            }
        }

        return [
            'requirements' => $requirements->values(),
            'checks' => $results,
            'passed_course' => $passedCourse,
            'best_match' => $bestMatch,
            'verdict' => $this->makeDepartmentVerdict($passedCourse, $bestMatch),
        ];
    }

    private function makeDepartmentVerdict(?int $passedCourse, ?array $bestMatch): string
    {
        if ($passedCourse !== null) {
            return "Matches department requirements for course {$passedCourse}.";
        }

        if ($bestMatch !== null) {
            return "Does not match department requirements. Closest course: {$bestMatch['course_number']} ({$bestMatch['passed_checks']}/{$bestMatch['total_checks']} checks passed).";
        }

        return 'Department requirements are not configured.';
    }

    public function calculateApiMetrics(array $results): array
    {
        $counts = array_fill_keys(['verified', 'mismatch', 'partial_match', 'ambiguous', 'not_found', 'incomplete', 'not_checkable', 'invalid_input'], 0);
        $similarities = [];
        foreach ($results as $entry) {
            $api = $entry['api_check'] ?? [];
            $status = $api['status'] ?? null;
            if (isset($counts[$status])) {
                $counts[$status]++;
            }
            if (isset($api['similarity'])) {
                $similarities[] = $api['similarity'];
            }
        }

        return [
            'api_found' => $counts['verified'],
            'api_not_found' => $counts['not_found'],
            'api_errors' => $counts['incomplete'],
            'api_average_similarity' => $similarities ? round(array_sum($similarities) / count($similarities)) : 0,
            'verified_count' => $counts['verified'], 'mismatch_count' => $counts['mismatch'],
            'partial_count' => $counts['partial_match'], 'ambiguous_count' => $counts['ambiguous'],
            'not_found_count' => $counts['not_found'], 'incomplete_count' => $counts['incomplete'],
            'not_checkable_count' => $counts['not_checkable'], 'invalid_input_count' => $counts['invalid_input'],
        ];
    }
}
