<?php

use App\Services\Bibliography\DeferredCheck;
use App\Services\Bibliography\SourceNormalizer;
use App\Services\Bibliography\SourceVerificationService;
use App\Services\ExternalApi\ProviderHttpClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;

beforeEach(function () {
    Cache::flush();
    Http::preventStrayRequests();
    config(['services.openalex.key' => null, 'services.openalex.url' => 'https://api.openalex.org']);
    foreach (['crossref', 'openlibrary', 'datacite', 'dblp', 'openalex'] as $provider) {
        config(['bibliography.providers.'.$provider.'.interval' => 0]);
    }
});

function crossrefWork(array $overrides = []): array
{
    return array_replace(json_decode(file_get_contents(base_path('tests/Fixtures/bibliography/crossref-search.json')), true)['message']['items'][0], $overrides);
}

function sourceFields(array $overrides = []): array
{
    return array_replace(['doi' => '10.1234/example', 'title' => 'Example Article', 'author' => 'Smith, John', 'year' => '2020'], $overrides);
}

function verifyFields(array $fields, string $type = 'article'): array
{
    return app(SourceVerificationService::class)->verify($fields, $type);
}

function fakeCrossref(array $work): void
{
    Http::fake(['api.crossref.org/works/*' => Http::response(['message' => $work])]);
}

test('exact doi verifies metadata and changing input reuses metadata but recomputes verdict', function () {
    fakeCrossref(crossrefWork());
    expect(verifyFields(sourceFields())['status'])->toBe('verified');
    $changed = verifyFields(sourceFields(['year' => '2018']));
    expect($changed['status'])->toBe('mismatch')->and($changed['found'])->toBeTrue()
        ->and($changed['field_checks']['year']['status'])->toBe('different')
        ->and($changed['provider_checks'][0]['cached'])->toBeTrue();
    Http::assertSentCount(1);
});

test('doi from another publication is a mismatch and is never hidden by title search', function () {
    fakeCrossref(crossrefWork(['title' => ['A completely different publication']]));
    expect(verifyFields(sourceFields())['status'])->toBe('mismatch');
    Http::assertSentCount(1);
});

test('identifiers work without a title and preserve internal punctuation', function () {
    fakeCrossref(crossrefWork(['DOI' => '10.1234/a.(b);c']));
    expect(verifyFields(['doi' => 'https://doi.org/10.1234/A.(b);c'])['status'])->toBe('verified');
    Http::assertSent(fn ($r) => str_contains($r->url(), rawurlencode('10.1234/a.(b);c')));
});

test('created date is not used as publication year and missing metadata stays partial', function () {
    $work = crossrefWork();
    unset($work['published']);
    $work['created'] = ['date-parts' => [[2020]]];
    fakeCrossref($work);
    $result = verifyFields(sourceFields());
    expect($result['status'])->toBe('partial_match')->and($result['field_checks']['year']['status'])->toBe('unavailable');
});

test('online and print years are checked explicitly rather than a blanket one year tolerance', function () {
    fakeCrossref(crossrefWork(['published-online' => ['date-parts' => [[2019]]], 'published-print' => ['date-parts' => [[2020]]]]));
    expect(verifyFields(sourceFields(['year' => '2019']))['status'])->toBe('verified');
    expect(verifyFields(sourceFields(['year' => '2021']))['status'])->toBe('mismatch');
});

test('same title with different authors or given names is not verified without identifiers', function (string $author) {
    Http::fake(['api.crossref.org/works*' => Http::response(['message' => ['items' => [crossrefWork()]]]),
        'dblp.org/*' => Http::response(['result' => ['hits' => ['@total' => '0']]])]);
    $fields = sourceFields(['author' => $author]);
    unset($fields['doi']);
    expect(verifyFields($fields)['status'])->not->toBe('verified');
})->with(['Jones, John', 'Smith, Jane']);

test('two close candidates are ambiguous and missing author metadata cannot verify', function () {
    $work = crossrefWork();
    Http::fake(['api.crossref.org/works*' => Http::response(['message' => ['items' => [$work, crossrefWork(['DOI' => '10.1234/other'])]]]),
        'dblp.org/*' => Http::response(['result' => ['hits' => ['@total' => '0']]])]);
    $fields = sourceFields();
    unset($fields['doi']);
    expect(verifyFields($fields)['status'])->toBe('ambiguous');
});

test('missing author metadata cannot verify a title and year', function () {
    $fields = sourceFields();
    unset($fields['doi']);
    Http::fake(['api.crossref.org/works*' => Http::response(['message' => ['items' => [crossrefWork(['author' => []])]]]),
        'dblp.org/*' => Http::response(['result' => ['hits' => ['@total' => '0']]])]);
    expect(verifyFields($fields)['status'])->toBe('partial_match');
});

test('crossref absence falls back to datacite even for an article doi', function () {
    Http::fake(['api.crossref.org/*' => Http::response([], 404), 'api.datacite.org/dois/*' => Http::response([
        'data' => ['id' => '10.1234/example', 'attributes' => ['titles' => [['title' => 'Example Article']],
            'creators' => [['familyName' => 'Smith', 'givenName' => 'John']], 'publicationYear' => 2020]],
    ])]);
    $result = verifyFields(sourceFields());
    expect($result['status'])->toBe('verified')->and($result['provider'])->toBe('datacite')->and($result['complete'])->toBeTrue();
});

test('network failures invalid json and throttling are incomplete rather than not found', function (int $code, mixed $body) {
    Http::fake(['api.crossref.org/*' => Http::response($body, $code, ['Retry-After' => '15']),
        'api.datacite.org/*' => Http::response([], 404)]);
    $result = verifyFields(sourceFields());
    expect($result['status'])->toBe('incomplete')->and($result['complete'])->toBeFalse();
    if ($code === 429) {
        expect($result['provider_checks'][0]['retry_after'])->toBe(15);
    }
})->with([[429, []], [500, []], [200, 'broken json'], [202, '<html>bot challenge</html>'], [200, ['unexpected' => true]]]);

test('connection timeout is incomplete and another provider may still confirm metadata', function () {
    Http::fake(['api.crossref.org/*' => Http::failedConnection(), 'api.datacite.org/*' => Http::response(['data' => ['id' => '10.1234/example', 'attributes' => []]])]);
    $result = verifyFields(['doi' => '10.1234/example']);
    expect($result['found'])->toBeTrue()->and($result['complete'])->toBeFalse()
        ->and($result['provider_checks'][0]['status'])->toBe('unavailable');
});

test('isbn normalization validates checksums and detects edition mismatches', function () {
    $normalizer = app(SourceNormalizer::class);
    expect($normalizer->isbn('0-14-032872-6'))->toBe('9780140328721')
        ->and($normalizer->isbn('0-8044-2957-X'))->toBe('9780804429573');
    expect(verifyFields(['isbn' => '9780140328722'], 'book')['status'])->toBe('invalid_input');
    Http::assertNothingSent();
    Http::fake(['openlibrary.org/isbn/*' => Http::response(['key' => '/books/OL1M', 'title' => 'Example Book',
        'publish_date' => '1988', 'publishers' => ['Puffin'], 'isbn_13' => ['9780140328721']])]);
    expect(verifyFields(['isbn' => '0140328726', 'year' => '2000', 'publisher' => 'Other Publisher'], 'book')['status'])->toBe('mismatch');
});

test('openlibrary author cards obey the same global limiter and are cached independently', function () {
    config(['bibliography.providers.openlibrary.interval' => 1]);
    Http::fake(['openlibrary.org/isbn/*' => Http::response(['key' => '/books/OL1M', 'title' => 'Example Book', 'isbn_13' => ['9780140328721'], 'authors' => [['key' => '/authors/OL1A']]]),
        'openlibrary.org/authors/*' => Http::response(['name' => 'John Smith'])]);
    expect(fn () => app(SourceVerificationService::class)->verify(['isbn' => '9780140328721', 'author' => 'Smith, John'], 'book', true))->toThrow(DeferredCheck::class);
    Http::assertSentCount(1);
    Cache::forget('bib:next:openlibrary');
    $result = verifyFields(['isbn' => '9780140328721', 'author' => 'Smith, John'], 'book');
    expect($result['status'])->toBe('verified');
    Http::assertSentCount(2);
});

test('work first publication year does not verify the requested edition', function () {
    Http::fake(['openlibrary.org/search.json*' => Http::response(['docs' => [['key' => '/works/OL1W', 'title' => 'Example Book', 'author_name' => ['John Smith'], 'first_publish_year' => 2020]]])]);
    $result = verifyFields(['title' => 'Example Book', 'author' => 'Smith, John', 'year' => '2020'], 'book');
    expect($result['status'])->toBe('partial_match')->and($result['field_checks']['year']['status'])->toBe('unavailable');
});

test('book search retrieves a concrete edition and confirms its publication year', function () {
    Http::fake(['openlibrary.org/search.json*' => Http::response(['docs' => [['key' => '/works/OL1W', 'title' => 'Example Book', 'author_name' => ['John Smith'], 'editions' => ['docs' => [['key' => '/books/OL1M']]]]]]),
        'openlibrary.org/books/*' => Http::response(['key' => '/books/OL1M', 'title' => 'Example Book', 'publish_date' => '2020'])]);
    expect(verifyFields(['title' => 'Example Book', 'author' => 'Smith, John', 'year' => '2020'], 'book')['status'])->toBe('verified');
    Http::assertSentCount(2);
});

test('dblp handles a singleton author and keeps its identifier separate', function () {
    Http::fake(['api.crossref.org/*' => Http::response(['message' => ['items' => []]]),
        'dblp.org/*' => Http::response(['result' => ['hits' => ['@total' => '1', 'hit' => [['info' => ['key' => 'journals/example/Smith20', 'doi' => '10.1234/example', 'title' => 'Example Article', 'authors' => ['author' => ['text' => 'John Smith']], 'year' => '2020']]]]]])]);
    $fields = sourceFields();
    unset($fields['doi']);
    $result = verifyFields($fields);
    expect($result['status'])->toBe('verified')->and($result['provider'])->toBe('dblp')
        ->and($result['external_data']['dblp_id'])->toBe('journals/example/Smith20');
});

test('search cache identity changes with author year type and algorithm version', function () {
    Http::fake(['api.crossref.org/*' => Http::response(['message' => ['items' => []]]),
        'dblp.org/*' => Http::response(['result' => ['hits' => ['@total' => '0']]])]);
    verifyFields(['title' => 'Example Article', 'author' => 'Smith', 'year' => '2020'], 'inbook');
    verifyFields(['title' => 'Example Article', 'author' => 'Smith', 'year' => '2021'], 'inbook');
    verifyFields(['title' => 'Example Article', 'author' => 'Jones', 'year' => '2020'], 'inbook');
    config(['bibliography.algorithm_version' => 'next']);
    verifyFields(['title' => 'Example Article', 'author' => 'Smith', 'year' => '2020'], 'inbook');
    Http::assertSentCount(4);
});

test('retraction and correction direction are independent of existence verdict', function (array $updates, string $status) {
    fakeCrossref(crossrefWork($updates));
    $result = verifyFields(sourceFields());
    expect($result['status'])->toBe('verified')->and($result['retraction']['status'])->toBe($status);
})->with([
    [['updated-by' => [['type' => 'retraction', 'DOI' => '10.1234/notice', 'source' => 'retraction-watch']]], 'retracted'],
    [['updated-by' => [['type' => 'correction', 'DOI' => '10.1234/correction']]], 'no_notice_found'],
    [['update-to' => [['type' => 'retraction', 'DOI' => '10.1234/other']]], 'no_notice_found'],
]);

test('global slots cooldown and request budget prevent excess traffic', function () {
    config(['bibliography.providers.crossref.interval' => 1]);
    Http::fake(['api.crossref.org/*' => Http::response(['message' => ['items' => []]])]);
    $client = new ProviderHttpClient;
    $lock = Cache::lock('bib:slot:crossref', 20);
    $lock->get();
    expect(fn () => $client->get('crossref', 'works', ['query.bibliographic' => 'one']))->toThrow(DeferredCheck::class);
    Http::assertNothingSent();
    $lock->release();
    $client->get('crossref', 'works', ['query.bibliographic' => 'one']);
    expect(fn () => $client->get('crossref', 'works', ['query.bibliographic' => 'two']))->toThrow(DeferredCheck::class);
    Http::assertSentCount(1);
    $client->requests = 6;
    expect($client->get('datacite', 'dois')['status'])->toBe('skipped');
    for ($i = 0; $i < 450; $i++) {
        RateLimiter::hit('bib:datacite:window', 300);
    }
    $otherClient = new ProviderHttpClient;
    expect(fn () => $otherClient->get('datacite', 'dois'))->toThrow(DeferredCheck::class);
    Http::assertSentCount(1);
});

test('openalex server key and quota headers never use the user key or turn exhaustion into absence', function () {
    config(['services.openalex.key' => 'server-secret']);
    Http::fake(['api.crossref.org/*' => Http::response(['message' => ['items' => []]]),
        'api.openalex.org/*' => Http::response([], 429, ['X-RateLimit-Remaining' => '0', 'X-RateLimit-Reset' => '300']),
        'dblp.org/*' => Http::response(['result' => ['hits' => ['@total' => '0']]])]);
    $result = verifyFields(['title' => 'Example Article']);
    expect($result['status'])->toBe('incomplete')->and(Cache::get('bib:quota:openalex'))->toBeTrue();
    Http::assertSent(fn ($request) => str_contains($request->url(), 'api.openalex.org') && $request->hasHeader('Authorization', 'Bearer server-secret') && ! str_contains($request->url(), 'secret'));
});

test('unicode and known latex formatting and accents normalize without erasing unknown math', function () {
    $normalizer = app(SourceNormalizer::class);
    expect($normalizer->text("\\textit{Caf\\'{e}}"))->toBe('café')
        ->and($normalizer->text('  ИССЛЕДОВАНИЕ   {ДАННЫХ} '))->toBe('исследование данных')
        ->and($normalizer->text('Energy $\\alpha$'))->toContain('\\alpha');
});

test('malformed nested author metadata is incomplete without a server error', function () {
    Http::fake(['api.crossref.org/*' => Http::response(['message' => crossrefWork(['author' => 'broken'])]),
        'api.datacite.org/*' => Http::response([], 404)]);
    expect(verifyFields(sourceFields())['status'])->toBe('incomplete');
});
