<?php

use Illuminate\Support\Facades\Http;

test('it verifies one source by fields', function () {
    Http::preventStrayRequests();

    Http::fake(['api.crossref.org/works*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/bibliography/crossref-search.json')), true))]);

    $response = $this->postJson('/api/sources/verify', [
        'fields' => [
            'title' => 'Example Article',
            'author' => 'Smith, John',
            'year' => '2020',
        ],
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.entry', null)
        ->assertJsonPath('data.api_check.found', true)
        ->assertJsonPath('data.api_check.external_title', 'Example Article');
});

test('it verifies one source by bibtex text', function () {
    Http::preventStrayRequests();

    Http::fake(['api.crossref.org/works*' => Http::response(json_decode(file_get_contents(base_path('tests/Fixtures/bibliography/crossref-search.json')), true))]);

    $bib = <<<'BIB'
@article{smith2020,
  title = {Example Article},
  author = {Smith, John},
  year = {2020}
}
BIB;

    $response = $this->postJson('/api/sources/verify', [
        'text' => $bib,
    ]);

    $response
        ->assertOk()
        ->assertJsonPath('data.entry.key', 'smith2020')
        ->assertJsonPath('data.api_check.found', true);
});
