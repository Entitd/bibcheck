<?php

use App\Models\CourseRequirement;
use Illuminate\Support\Facades\Http;

function validArticleBib(): string
{
    return <<<'BIB'
@article{smith2020,
  author = {Smith, John},
  title = {Example Article},
  journal = {Journal of Examples},
  year = {2020},
  pages = {1-10},
  volume = {1},
  number = {2},
  hyphenation = {english},
  langid = {english}
}
BIB;
}

test('it checks bibtex content against gost rules', function () {
    $response = $this->postJson('/api/bib/check-gost', [
        'text' => validArticleBib(),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.aggregated_metrics.totalQuantity', 1)
        ->assertJsonPath('data.entries.smith2020.type', 'article')
        ->assertJsonPath('data.entries.smith2020.line', 1)
        ->assertJsonCount(0, 'data.errors');
});

test('it validates that either text or file is provided', function () {
    $response = $this->postJson('/api/bib/check-gost');

    $response
        ->assertStatus(422)
        ->assertJsonStructure([
            'message',
            'errors',
        ]);
});

test('it checks bibtex content against department requirements', function () {
    CourseRequirement::create([
        'course_number' => 1,
        'min_total_quantity' => 1,
        'min_foreign_lang' => 1,
        'min_current_periodicals' => 1,
        'min_21st_century' => 1,
    ]);

    $response = $this->postJson('/api/bib/check-department', [
        'text' => validArticleBib(),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.aggregated_metrics.totalQuantity', 1)
        ->assertJsonPath('data.department.passed_course', 1)
        ->assertJsonPath('data.department.checks.0.passed', true);
});

test('it checks bibtex content against external sources', function () {
    Http::preventStrayRequests();

    Http::fake(['api.crossref.org/works*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/bibliography/crossref-search.json')), true))]);

    $response = $this->postJson('/api/bib/check-external', [
        'text' => validArticleBib(),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.entries.smith2020.api_check.found', true)
        ->assertJsonPath('data.aggregated_metrics.api_found', 1);
});

test('it runs full bibtex check', function () {
    CourseRequirement::create([
        'course_number' => 1,
        'min_total_quantity' => 1,
        'min_foreign_lang' => 1,
        'min_current_periodicals' => 1,
        'min_21st_century' => 1,
    ]);

    Http::preventStrayRequests();

    Http::fake(['api.crossref.org/works*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/bibliography/crossref-search.json')), true))]);

    $response = $this->postJson('/api/bib/check-full', [
        'text' => validArticleBib(),
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.entries.smith2020.api_check.found', true)
        ->assertJsonPath('data.department.passed_course', 1)
        ->assertJsonPath('data.aggregated_metrics.api_found', 1);
});
